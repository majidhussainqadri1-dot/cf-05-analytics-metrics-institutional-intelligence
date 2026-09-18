<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\ExportControlService;
use Sabri\AnalyticsIntelligence\Domain\ReportControlService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\IdempotencyGuard;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class GovernanceRestController
{
    public function __construct(private Database $db) {}
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }

    public function routes(): void
    {
        $namespace='sabri-analytics/v1';
        register_rest_route($namespace,'/exports/(?P<uuid>[0-9a-fA-F-]{36})/revoke',[
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>fn(WP_REST_Request $request)=>$this->mutation('export-revoke:'.(string)$request['uuid'],$request,fn(array $p)=>(new ExportControlService($this->db))->revoke((string)$request['uuid'],(string)($p['reason']??''),get_current_user_id())),
            'permission_callback'=>static fn():bool=>current_user_can('smai_export_metrics')||current_user_can('smai_manage_access'),
        ]);
        register_rest_route($namespace,'/reports/(?P<uuid>[0-9a-fA-F-]{36})',[
            'methods'=>WP_REST_Server::EDITABLE,
            'callback'=>fn(WP_REST_Request $request)=>$this->mutation('report-update:'.(string)$request['uuid'],$request,fn(array $p)=>(new ReportControlService($this->db))->update((string)$request['uuid'],(int)($p['row_version']??0),is_array($p['changes']??null)?$p['changes']:[],get_current_user_id())),
            'permission_callback'=>static fn():bool=>current_user_can('smai_manage_reports'),
        ]);
        register_rest_route($namespace,'/reports/(?P<uuid>[0-9a-fA-F-]{36})/pause',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>fn(WP_REST_Request $request)=>$this->reportTransition($request,'pause'),'permission_callback'=>static fn():bool=>current_user_can('smai_manage_reports'),
        ]);
        register_rest_route($namespace,'/reports/(?P<uuid>[0-9a-fA-F-]{36})/resume',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>fn(WP_REST_Request $request)=>$this->reportTransition($request,'resume'),'permission_callback'=>static fn():bool=>current_user_can('smai_approve_catalog'),
        ]);
        register_rest_route($namespace,'/reports/(?P<uuid>[0-9a-fA-F-]{36})/revoke',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>fn(WP_REST_Request $request)=>$this->reportTransition($request,'revoke'),'permission_callback'=>static fn():bool=>current_user_can('smai_manage_reports')||current_user_can('smai_manage_access'),
        ]);
        register_rest_route($namespace,'/reports/(?P<uuid>[0-9a-fA-F-]{36})/unsubscribe',[
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>fn(WP_REST_Request $request)=>$this->mutation('report-unsubscribe:'.(string)$request['uuid'],$request,fn(array $p)=>(new ReportControlService($this->db))->unsubscribe((string)$request['uuid'],(int)($p['row_version']??0),get_current_user_id())),
            'permission_callback'=>static fn():bool=>is_user_logged_in(),
        ]);
    }

    private function reportTransition(WP_REST_Request $request,string $action):WP_REST_Response|WP_Error
    {
        return $this->mutation('report-'.$action.':'.(string)$request['uuid'],$request,function(array $p)use($request,$action){
            $service=new ReportControlService($this->db);$args=[(string)$request['uuid'],(int)($p['row_version']??0),(string)($p['reason']??''),get_current_user_id()];
            return match($action){'pause'=>$service->pause(...$args),'resume'=>$service->resume(...$args),'revoke'=>$service->revoke(...$args),default=>new WP_Error('smai_invalid_report_action','Invalid report action.',['status'=>400])};
        });
    }

    /** @param callable(array<string,mixed>):(array<string,mixed>|WP_Error) $callback */
    private function mutation(string $scope,WP_REST_Request $request,callable $callback,int $status=200):WP_REST_Response|WP_Error
    {
        $payload=$this->payload($request);if(is_wp_error($payload))return $payload;
        $key=(string)$request->get_header('idempotency-key');$actor=(string)get_current_user_id();$guard=new IdempotencyGuard($this->db);
        $begin=$guard->begin($scope,$actor,$key,hash('sha256',Json::canonical($payload)));if(is_wp_error($begin))return $begin;
        if($begin['state']==='completed')return $this->response(is_array($begin['response'])?$begin['response']:[],(int)($begin['status_code']??$status));
        try{$result=$callback($payload);}catch(\Throwable $error){$guard->release($scope,$actor,$key);return new WP_Error('smai_governance_mutation_failed','The governed operation failed safely.',['status'=>500,'trace_id'=>wp_generate_uuid4()]);}
        if(is_wp_error($result)){$guard->release($scope,$actor,$key);return $result;}
        if(!$guard->finish($scope,$actor,$key,$result,$status))return new WP_Error('smai_idempotency_finalize_failed','The operation completed but its idempotency evidence could not be finalized.',['status'=>500]);
        return $this->response($result,$status);
    }

    /** @return array<string,mixed>|WP_Error */
    private function payload(WP_REST_Request $request):array|WP_Error
    {
        if(strlen((string)$request->get_body())>1024*1024)return new WP_Error('smai_request_too_large','Request exceeds the maximum size.',['status'=>413]);
        $raw=trim((string)$request->get_body());$decoded=$raw===''?new \stdClass():json_decode($raw);if(!($decoded instanceof \stdClass))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);
        $payload=$request->get_json_params();if(!is_array($payload))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);return $payload;
    }

    private function response(array $result,int $status=200):WP_REST_Response
    {
        $response=new WP_REST_Response($result,$status);$response->header('Cache-Control','no-store, private');$response->header('X-Content-Type-Options','nosniff');$response->header('X-Sabri-Trace-ID',wp_generate_uuid4());return $response;
    }
}
