<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\ExportControlService;
use Sabri\AnalyticsIntelligence\Domain\ReportControlService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class GovernanceRestController
{
    public function __construct(private Database $db)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $namespace = 'sabri-analytics/v1';

        register_rest_route($namespace, '/exports/(?P<uuid>[0-9a-fA-F-]{36})/revoke', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn(WP_REST_Request $request) => $this->response(
                (new ExportControlService($this->db))->revoke(
                    (string) $request['uuid'],
                    (string) (((array) $request->get_json_params())['reason'] ?? ''),
                    get_current_user_id()
                )
            ),
            'permission_callback' => static fn(): bool => current_user_can('smai_export_metrics') || current_user_can('smai_manage_access') || current_user_can('smai_audit'),
        ]);

        register_rest_route($namespace, '/reports/(?P<uuid>[0-9a-fA-F-]{36})', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => fn(WP_REST_Request $request) => $this->response(
                (new ReportControlService($this->db))->update(
                    (string) $request['uuid'],
                    (int) (((array) $request->get_json_params())['row_version'] ?? 0),
                    is_array(((array) $request->get_json_params())['changes'] ?? null) ? ((array) $request->get_json_params())['changes'] : [],
                    get_current_user_id()
                )
            ),
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_reports'),
        ]);

        register_rest_route($namespace, '/reports/(?P<uuid>[0-9a-fA-F-]{36})/pause', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn(WP_REST_Request $request) => $this->reportTransition($request, 'pause'),
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_reports'),
        ]);
        register_rest_route($namespace, '/reports/(?P<uuid>[0-9a-fA-F-]{36})/resume', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn(WP_REST_Request $request) => $this->reportTransition($request, 'resume'),
            'permission_callback' => static fn(): bool => current_user_can('smai_approve_catalog'),
        ]);
        register_rest_route($namespace, '/reports/(?P<uuid>[0-9a-fA-F-]{36})/revoke', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn(WP_REST_Request $request) => $this->reportTransition($request, 'revoke'),
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_reports') || current_user_can('smai_manage_access'),
        ]);
        register_rest_route($namespace, '/reports/(?P<uuid>[0-9a-fA-F-]{36})/unsubscribe', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn(WP_REST_Request $request) => $this->response(
                (new ReportControlService($this->db))->unsubscribe(
                    (string) $request['uuid'],
                    (int) (((array) $request->get_json_params())['row_version'] ?? 0),
                    get_current_user_id()
                )
            ),
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
    }

    private function reportTransition(WP_REST_Request $request, string $action): WP_REST_Response|WP_Error
    {
        $payload = (array) $request->get_json_params();
        $service = new ReportControlService($this->db);
        $args = [
            (string) $request['uuid'],
            (int) ($payload['row_version'] ?? 0),
            (string) ($payload['reason'] ?? ''),
            get_current_user_id(),
        ];
        $result = match ($action) {
            'pause' => $service->pause(...$args),
            'resume' => $service->resume(...$args),
            'revoke' => $service->revoke(...$args),
            default => new WP_Error('smai_invalid_report_action', 'Invalid report action.', ['status' => 400]),
        };
        return $this->response($result);
    }

    private function response(array|WP_Error $result): WP_REST_Response|WP_Error
    {
        if (is_wp_error($result)) {
            return $result;
        }
        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
