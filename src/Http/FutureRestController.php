<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\FutureFeatureRegistry;
use Sabri\AnalyticsIntelligence\Domain\FutureFeatureService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class FutureRestController
{
    public function __construct(private Database $db) {}

    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }

    public function routes(): void
    {
        $namespace = 'sabri-analytics/v1';
        register_rest_route($namespace, '/future/features', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn() => $this->response(['features' => (new FutureFeatureService($this->db))->list()]),
            'permission_callback' => static fn(): bool => current_user_can('smai_view_insights') || current_user_can('smai_audit'),
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/configure', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $payload = (array) $request->get_json_params();
                return $this->response((new FutureFeatureService($this->db))->configure((string)$request['feature_id'], is_array($payload['config']??null)?$payload['config']:[], get_current_user_id(), max(0,(int)($payload['row_version']??0))));
            },
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_future_intelligence'),
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/transition', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $payload=(array)$request->get_json_params();
                return $this->response((new FutureFeatureService($this->db))->transition((string)$request['feature_id'], strtolower((string)($payload['action']??'')), (string)($payload['reason']??''), get_current_user_id(), max(0,(int)($payload['row_version']??0))));
            },
            'permission_callback' => static function (WP_REST_Request $request): bool {
                $payload=(array)$request->get_json_params();$action=strtolower((string)($payload['action']??''));
                return $action==='approve'||$action==='activate' ? current_user_can('smai_approve_future_intelligence') : current_user_can('smai_manage_future_intelligence');
            },
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $payload=(array)$request->get_json_params();
                return $this->response((new FutureFeatureService($this->db))->run((string)$request['feature_id'], is_array($payload['input']??null)?$payload['input']:[], get_current_user_id(), (bool)($payload['dry_run']??false)));
            },
            'permission_callback' => static function (WP_REST_Request $request): bool {
                $definition=FutureFeatureRegistry::get((string)$request['feature_id']);if($definition===null)return false;$required=(string)($definition['capability']??'smai_run_future_intelligence');
                return current_user_can($required)||current_user_can('smai_run_future_intelligence');
            },
        ]);
        register_rest_route($namespace, '/future/incidents', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>fn()=>$this->response(['incidents'=>(new FutureFeatureService($this->db))->incidents()]),'permission_callback'=>static fn():bool=>current_user_can('smai_manage_quality')||current_user_can('smai_audit')],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>fn(WP_REST_Request $request)=>$this->response((new FutureFeatureService($this->db))->createIncident((array)$request->get_json_params(),get_current_user_id())),'permission_callback'=>static fn():bool=>current_user_can('smai_manage_quality')],
        ]);
    }

    private function response(array|WP_Error $result): WP_REST_Response|WP_Error
    {
        if (is_wp_error($result)) return $result;
        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
