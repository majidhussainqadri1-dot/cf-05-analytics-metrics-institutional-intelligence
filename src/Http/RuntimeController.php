<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\OperationsService;
use Sabri\AnalyticsIntelligence\Domain\RuntimeService;
use WP_REST_Request;
use WP_REST_Response;

final class RuntimeController
{
    public function __construct(private RuntimeService $runtime, private OperationsService $operations)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $this->route('/datasets', 'POST', 'smai_manage_catalog', fn(WP_REST_Request $r) => $this->runtime->registerDataset($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/lineage', 'POST', 'smai_manage_catalog', fn(WP_REST_Request $r) => $this->runtime->addLineage($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/pipeline/jobs', 'POST', 'smai_manage_quality', fn(WP_REST_Request $r) => $this->runtime->createPipelineJob($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/pipeline/jobs/(?P<uuid>[a-f0-9-]{36})/transition', 'POST', 'smai_manage_quality', fn(WP_REST_Request $r) => $this->runtime->transitionPipelineJob((string) $r['uuid'], sanitize_key((string) $r->get_param('target')), get_current_user_id(), sanitize_text_field((string) $r->get_param('reason'))));
            $this->route('/snapshots', 'POST', 'smai_manage_quality', fn(WP_REST_Request $r) => $this->operations->publishSnapshot($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/reports', 'POST', 'smai_view_insights', fn(WP_REST_Request $r) => $this->operations->createScheduledReport($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/exports', 'POST', 'smai_export_metrics', fn(WP_REST_Request $r) => $this->operations->requestExport($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/experiments', 'POST', 'smai_manage_experiments', fn(WP_REST_Request $r) => $this->runtime->createExperiment($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/decisions', 'POST', 'smai_manage_experiments', fn(WP_REST_Request $r) => $this->runtime->createDecision($r->get_json_params() ?: [], get_current_user_id()));
            $this->route('/deletions', 'POST', 'smai_manage_access', fn(WP_REST_Request $r) => $this->operations->propagateDeletion(sanitize_key((string) $r->get_param('source_module')), sanitize_text_field((string) $r->get_param('source_version')), (string) $r->get_param('deletion_key'), get_current_user_id()));
            register_rest_route('analytics/v1', '/runtime-health', [
                'methods' => 'GET',
                'permission_callback' => static fn(): bool => current_user_can('smai_audit'),
                'callback' => fn(): WP_REST_Response => new WP_REST_Response($this->runtime->health(), 200),
            ]);
        });
    }

    private function route(string $path, string $methods, string $capability, callable $callback): void
    {
        register_rest_route('analytics/v1', $path, [
            'methods' => $methods,
            'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can($capability),
            'callback' => static function (WP_REST_Request $request) use ($callback): WP_REST_Response|\WP_Error {
                $result = $callback($request);
                return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
            },
        ]);
    }
}
