<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\CatalogLifecycleService;
use Sabri\AnalyticsIntelligence\Domain\EventIngestionService;
use Sabri\AnalyticsIntelligence\Domain\EventSchemaRegistry;
use Sabri\AnalyticsIntelligence\Domain\MetricCatalog;
use Sabri\AnalyticsIntelligence\Domain\MetricQueryService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    private Database $db;
    private HealthService $health;

    public function __construct(Database $db, HealthService $health)
    {
        $this->db = $db;
        $this->health = $health;
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('sabri-analytics/v1', '/health', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn(WP_REST_Request $request): WP_REST_Response => new WP_REST_Response($this->health->report(current_user_can('smai_manage_quality'))),
                'permission_callback' => fn(): bool => current_user_can('smai_view_insights'),
            ]);

            register_rest_route('sabri-analytics/v1', '/events', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'ingestEvent'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('sabri-analytics/v1', '/catalog/events', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'registerEventSchema'],
                'permission_callback' => fn(): bool => current_user_can('smai_manage_catalog'),
            ]);

            register_rest_route('sabri-analytics/v1', '/catalog/metrics', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'registerMetric'],
                'permission_callback' => fn(): bool => current_user_can('smai_manage_catalog'),
            ]);

            register_rest_route('sabri-analytics/v1', '/catalog/events/(?P<id>\d+)/transition', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn(WP_REST_Request $request): WP_REST_Response|WP_Error => $this->transitionCatalogObject($request, 'event_schema'),
                'permission_callback' => fn(): bool => current_user_can('smai_approve_catalog'),
            ]);

            register_rest_route('sabri-analytics/v1', '/catalog/metrics/(?P<id>\d+)/transition', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn(WP_REST_Request $request): WP_REST_Response|WP_Error => $this->transitionCatalogObject($request, 'metric'),
                'permission_callback' => fn(): bool => current_user_can('smai_approve_catalog'),
            ]);

            register_rest_route('sabri-analytics/v1', '/metrics/(?P<metric_id>[a-z][a-z0-9_.-]{2,189})', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'queryMetric'],
                'permission_callback' => fn(): bool => current_user_can('smai_query_metrics'),
                'args' => [
                    'version' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'window_start' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'window_end' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'purpose' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]);
        });
    }

    public function ingestEvent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $service = (new ServiceAuthenticator($this->db))->authenticate($request);
        if (is_wp_error($service)) {
            return $service;
        }
        $event = $request->get_json_params();
        if (!is_array($event)) {
            return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $result = (new EventIngestionService($this->db))->ingest($event, $service);
        return is_wp_error($result) ? $result : new WP_REST_Response($result, $result['status'] === 'accepted' ? 202 : 200);
    }

    public function registerEventSchema(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $result = (new EventSchemaRegistry($this->db))->register($payload, get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
    }

    public function registerMetric(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $result = (new MetricCatalog($this->db))->register($payload, get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
    }

    public function transitionCatalogObject(WP_REST_Request $request, string $objectType): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        $result = (new CatalogLifecycleService($this->db))->transition(
            $objectType,
            (int) $request['id'],
            sanitize_key((string) ($payload['target_state'] ?? '')),
            (int) ($payload['row_version'] ?? 0),
            (string) ($payload['reason'] ?? ''),
            get_current_user_id()
        );
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    public function queryMetric(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $dimensions = $request->get_param('dimensions');
        if (is_string($dimensions) && $dimensions !== '') {
            $decoded = json_decode($dimensions, true);
            $dimensions = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($dimensions)) {
            $dimensions = [];
        }
        $clean = [];
        foreach (array_slice($dimensions, 0, 10, true) as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) || !is_scalar($value)) {
                continue;
            }
            $clean[$key] = is_string($value) ? Text::truncate(sanitize_text_field($value), 100) : $value;
        }

        $result = (new MetricQueryService($this->db))->query(
            (string) $request['metric_id'],
            (string) $request->get_param('version'),
            (string) $request->get_param('window_start'),
            (string) $request->get_param('window_end'),
            $clean,
            get_current_user_id(),
            Text::truncate((string) $request->get_param('purpose'), 190)
        );
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }
}
