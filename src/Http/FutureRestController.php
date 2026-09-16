<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\FutureFeatureRegistry;
use Sabri\AnalyticsIntelligence\Domain\FutureFeatureService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\FutureActivationService;
use Sabri\AnalyticsIntelligence\Infrastructure\IdempotencyGuard;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
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
        register_rest_route($namespace, '/future/runtime/activation/propose', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-runtime-activation-propose', $request, function (array $payload) {
                    return (new FutureActivationService($this->db))->propose((string)($payload['evidence_hash']??''),(string)($payload['reason']??''),get_current_user_id());
                }, 201);
            },
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_future_intelligence'),
        ]);
        register_rest_route($namespace, '/future/runtime/activation/approve', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-runtime-activation-approve', $request, function (array $payload) {
                    return (new FutureActivationService($this->db))->approve((string)($payload['request_hash']??''),get_current_user_id());
                });
            },
            'permission_callback' => static fn(): bool => current_user_can('smai_approve_future_intelligence'),
        ]);
        register_rest_route($namespace, '/future/runtime/disable', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-runtime-disable', $request, function (array $payload) {
                    return (new FutureActivationService($this->db))->disable((string)($payload['reason']??''),get_current_user_id());
                });
            },
            'permission_callback' => static fn(): bool => current_user_can('smai_approve_future_intelligence'),
        ]);
        register_rest_route($namespace, '/future/features', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn() => $this->response(['features' => (new FutureFeatureService($this->db))->list()]),
            'permission_callback' => static fn(): bool => current_user_can('smai_view_insights') || current_user_can('smai_audit'),
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/configure', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-feature-configure:' . (string)$request['feature_id'], $request, function (array $payload) use ($request) {
                    return (new FutureFeatureService($this->db))->configure((string)$request['feature_id'], is_array($payload['config']??null)?$payload['config']:[], get_current_user_id(), max(0,(int)($payload['row_version']??0)));
                });
            },
            'permission_callback' => static fn(): bool => current_user_can('smai_manage_future_intelligence'),
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/transition', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-feature-transition:' . (string)$request['feature_id'], $request, function (array $payload) use ($request) {
                    return (new FutureFeatureService($this->db))->transition((string)$request['feature_id'], strtolower((string)($payload['action']??'')), (string)($payload['reason']??''), get_current_user_id(), max(0,(int)($payload['row_version']??0)));
                });
            },
            'permission_callback' => static function (WP_REST_Request $request): bool {
                $payload=(array)$request->get_json_params();$action=strtolower((string)($payload['action']??''));
                return $action==='approve'||$action==='activate' ? current_user_can('smai_approve_future_intelligence') : current_user_can('smai_manage_future_intelligence');
            },
        ]);
        register_rest_route($namespace, '/future/features/(?P<feature_id>CF05-FUT-\d{3})/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                return $this->mutation('future-feature-run:' . (string)$request['feature_id'], $request, function (array $payload) use ($request) {
                    return (new FutureFeatureService($this->db))->run((string)$request['feature_id'], is_array($payload['input']??null)?$payload['input']:[], get_current_user_id(), (bool)($payload['dry_run']??false));
                });
            },
            'permission_callback' => static function (WP_REST_Request $request): bool {
                $definition=FutureFeatureRegistry::get((string)$request['feature_id']);if($definition===null)return false;$required=(string)($definition['capability']??'');
                return $required!=='' && current_user_can($required);
            },
        ]);
        register_rest_route($namespace, '/future/incidents', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>fn()=>$this->response(['incidents'=>(new FutureFeatureService($this->db))->incidents()]),'permission_callback'=>static fn():bool=>current_user_can('smai_manage_quality')||current_user_can('smai_audit')],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>function(WP_REST_Request $request):WP_REST_Response|WP_Error {
                return $this->mutation('future-incident-create',$request,fn(array $payload)=>(new FutureFeatureService($this->db))->createIncident($payload,get_current_user_id()),201);
            },'permission_callback'=>static fn():bool=>current_user_can('smai_manage_quality')],
        ]);
    }

    /** @param callable(array<string,mixed>):(array<string,mixed>|WP_Error) $callback */
    private function mutation(string $scope, WP_REST_Request $request, callable $callback, int $status = 200): WP_REST_Response|WP_Error
    {
        $payload = $this->payload($request);
        if (is_wp_error($payload)) return $payload;
        $key = (string)$request->get_header('idempotency-key');
        $actor = (string)get_current_user_id();
        $guard = new IdempotencyGuard($this->db);
        $begin = $guard->begin($scope, $actor, $key, hash('sha256', Json::canonical($payload)));
        if (is_wp_error($begin)) return $begin;
        if ($begin['state'] === 'completed') return $this->response(is_array($begin['response']) ? $begin['response'] : [], (int)($begin['status_code'] ?? $status));
        try {
            $result = $callback($payload);
        } catch (\Throwable $error) {
            $guard->release($scope, $actor, $key);
            return new WP_Error('smai_future_mutation_failed', 'The governed Future-40 operation failed safely.', ['status'=>500,'trace_id'=>wp_generate_uuid4()]);
        }
        if (is_wp_error($result)) {
            $guard->release($scope, $actor, $key);
            return $result;
        }
        if (!$guard->finish($scope, $actor, $key, $result, $status)) {
            return new WP_Error('smai_idempotency_finalize_failed', 'The operation completed but its idempotency evidence could not be finalized.', ['status'=>500]);
        }
        return $this->response($result, $status);
    }

    /** @return array<string,mixed>|WP_Error */
    private function payload(WP_REST_Request $request): array|WP_Error
    {
        if (strlen((string)$request->get_body()) > 1024 * 1024) return new WP_Error('smai_request_too_large', 'Request exceeds the maximum size.', ['status'=>413]);
        $payload = $request->get_json_params();
        if (!is_array($payload)) return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status'=>400]);
        return $payload;
    }

    private function response(array $result, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($result, $status);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Sabri-Trace-ID', wp_generate_uuid4());
        return $response;
    }
}
