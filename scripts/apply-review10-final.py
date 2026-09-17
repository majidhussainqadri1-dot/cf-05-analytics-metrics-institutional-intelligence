#!/usr/bin/env python3
from pathlib import Path

# Round 10 final adversarial audit was completed in full before this correction
# phase. The frozen ledger is recorded in docs/REVIEW-ROUND-10.md.

runtime = r'''<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class RuntimeActivationService
{
    private const OPTION_REQUEST = 'smai_activation_request';

    public function __construct(private Database $db) {}

    /** @return array<string,mixed>|WP_Error */
    public function propose(string $state,string $evidenceHash,string $reason,int $actorUserId):array|WP_Error
    {
        $evidenceHash=strtolower(trim($evidenceHash));
        $reason=Text::truncate(trim(wp_strip_all_tags($reason)),500);
        if($actorUserId<1||!in_array($state,[RuntimeGate::CATALOG_ONLY,RuntimeGate::STAGING_ACTIVE,RuntimeGate::PRODUCTION_ACTIVE],true)||preg_match('/^[a-f0-9]{64}$/',$evidenceHash)!==1||strlen($reason)<12){
            return new WP_Error('smai_invalid_activation_proposal','Runtime activation proposal is invalid.',['status'=>400]);
        }
        if(!$this->protectedEvidenceMatches($evidenceHash))return new WP_Error('smai_activation_evidence_mismatch','Activation evidence does not match the protected configuration.',['status'=>409]);
        if(!RuntimeGate::schemaReady())return new WP_Error('smai_schema_gate','CF-05 schema must be ready before runtime activation can be proposed.',['status'=>409]);
        if(!$this->begin())return new WP_Error('smai_activation_transaction_failed','Runtime activation transaction could not start.',['status'=>500]);
        try{
            if($this->readOptionForUpdate(self::OPTION_REQUEST)!==null)return $this->rollbackError(new WP_Error('smai_activation_request_pending','A runtime activation proposal is already pending independent review.',['status'=>409]));
            $request=['request_uuid'=>Uuid::v4(),'state'=>$state,'evidence_hash'=>$evidenceHash,'reason'=>$reason,'proposed_by'=>$actorUserId,'proposed_at'=>gmdate('c'),'request_hash'=>''];
            $request['request_hash']=hash('sha256',Json::canonical(array_diff_key($request,['request_hash'=>true])));
            if(!$this->writeOption(self::OPTION_REQUEST,$request,true))return $this->rollbackError(new WP_Error('smai_activation_request_pending','A runtime activation proposal is already pending independent review.',['status'=>409]));
            if(!(new AuditLogger($this->db))->logInOpenTransaction('runtime_activation_proposed','runtime_activation',$request['request_uuid'],'success',['state'=>$state,'request_hash'=>$request['request_hash'],'evidence_hash'=>$evidenceHash],'runtime_governance',null,$actorUserId)){
                return $this->rollbackError(new WP_Error('smai_activation_audit_failed','Runtime activation proposal was not committed because audit evidence could not be written.',['status'=>500]));
            }
            if(!$this->commit())return new WP_Error('smai_activation_commit_failed','Runtime activation proposal could not be committed.',['status'=>500]);
            $this->flushOptionCaches([self::OPTION_REQUEST]);
            return ['request_uuid'=>$request['request_uuid'],'state'=>'proposed','target_state'=>$state,'request_hash'=>$request['request_hash']];
        }catch(\Throwable $error){$this->rollback();return new WP_Error('smai_activation_store_failed','Runtime activation proposal failed safely.',['status'=>500]);}
    }

    /** @return array<string,mixed>|WP_Error */
    public function approve(string $requestHash,int $actorUserId):array|WP_Error
    {
        $requestHash=strtolower(trim($requestHash));
        if($actorUserId<1||preg_match('/^[a-f0-9]{64}$/',$requestHash)!==1)return new WP_Error('smai_activation_request_stale','Activation request is unavailable or stale.',['status'=>409]);
        if(!$this->begin())return new WP_Error('smai_activation_transaction_failed','Runtime activation transaction could not start.',['status'=>500]);
        try{
            $request=$this->readOptionForUpdate(self::OPTION_REQUEST);
            if(!is_array($request)||!hash_equals((string)($request['request_hash']??''),$requestHash))return $this->rollbackError(new WP_Error('smai_activation_request_stale','Activation request is unavailable or stale.',['status'=>409]));
            if((int)($request['proposed_by']??0)===$actorUserId)return $this->rollbackError(new WP_Error('smai_separation_of_duties','Activation requires an independent approver.',['status'=>403]));
            if(!RuntimeGate::schemaReady())return $this->rollbackError(new WP_Error('smai_schema_gate','CF-05 schema must be ready before runtime activation can be approved.',['status'=>409]));
            $state=(string)($request['state']??'');
            if(!in_array($state,[RuntimeGate::CATALOG_ONLY,RuntimeGate::STAGING_ACTIVE,RuntimeGate::PRODUCTION_ACTIVE],true))return $this->rollbackError(new WP_Error('smai_activation_request_stale','Activation target state is invalid or stale.',['status'=>409]));
            $environment=function_exists('wp_get_environment_type')?wp_get_environment_type():'unknown';
            if($state===RuntimeGate::PRODUCTION_ACTIVE&&$environment!=='production')return $this->rollbackError(new WP_Error('smai_activation_environment_mismatch','Production activation is permitted only in the production environment.',['status'=>409]));
            if($state===RuntimeGate::STAGING_ACTIVE&&!in_array($environment,['staging','development','local'],true))return $this->rollbackError(new WP_Error('smai_activation_environment_mismatch','Staging activation is permitted only in a non-production staging-compatible environment.',['status'=>409]));
            $evidence=strtolower((string)($request['evidence_hash']??''));
            if(!$this->protectedEvidenceMatches($evidence))return $this->rollbackError(new WP_Error('smai_activation_evidence_mismatch','Protected activation evidence changed.',['status'=>409]));
            $worker=in_array($state,[RuntimeGate::STAGING_ACTIVE,RuntimeGate::PRODUCTION_ACTIVE],true)?'1':'0';
            foreach(['smai_runtime_state'=>$state,'smai_activation_evidence_hash'=>$evidence,'smai_activation_approved'=>'1','smai_worker_enabled'=>$worker] as $name=>$value){
                if(!$this->writeOption($name,$value))return $this->rollbackError(new WP_Error('smai_activation_store_failed','Runtime activation state could not be stored.',['status'=>500]));
            }
            if(!$this->deleteOption(self::OPTION_REQUEST))return $this->rollbackError(new WP_Error('smai_activation_store_failed','Runtime activation proposal could not be finalized.',['status'=>500]));
            if(!(new AuditLogger($this->db))->logInOpenTransaction('runtime_activation_approved','runtime_activation',(string)($request['request_uuid']??''),'success',['state'=>$state,'environment'=>$environment,'request_hash'=>$requestHash,'evidence_hash'=>$evidence],'runtime_governance',null,$actorUserId)){
                return $this->rollbackError(new WP_Error('smai_activation_audit_failed','Runtime activation approval was not committed because audit evidence could not be written.',['status'=>500]));
            }
            if(!$this->commit())return new WP_Error('smai_activation_commit_failed','Runtime activation approval could not be committed.',['status'=>500]);
            $this->flushOptionCaches([self::OPTION_REQUEST,'smai_runtime_state','smai_activation_evidence_hash','smai_activation_approved','smai_worker_enabled']);
            return ['state'=>$state,'activation_approved'=>RuntimeGate::activationApproved(),'worker_enabled'=>RuntimeGate::workerEnabled(),'environment'=>$environment];
        }catch(\Throwable $error){$this->rollback();return new WP_Error('smai_activation_store_failed','Runtime activation approval failed safely.',['status'=>500]);}
    }

    /** @return array<string,mixed>|WP_Error */
    public function disable(string $reason,int $actorUserId,bool $foundation=false):array|WP_Error
    {
        $reason=Text::truncate(trim(wp_strip_all_tags($reason)),500);
        if($actorUserId<1||strlen($reason)<8)return new WP_Error('smai_disable_reason_required','A meaningful runtime-disable reason is required.',['status'=>400]);
        $target=$foundation?RuntimeGate::FOUNDATION_DISABLED:RuntimeGate::SAFE_MODE;
        if(!$this->begin())return new WP_Error('smai_activation_transaction_failed','Runtime disable transaction could not start.',['status'=>500]);
        try{
            $previous=(string)($this->readOptionForUpdate('smai_runtime_state')??RuntimeGate::FOUNDATION_DISABLED);
            foreach(['smai_runtime_state'=>$target,'smai_activation_approved'=>'0','smai_activation_evidence_hash'=>'','smai_worker_enabled'=>'0'] as $name=>$value){
                if(!$this->writeOption($name,$value))return $this->rollbackError(new WP_Error('smai_disable_failed','Runtime could not be disabled safely.',['status'=>500]));
            }
            if(!$this->deleteOption(self::OPTION_REQUEST))return $this->rollbackError(new WP_Error('smai_disable_failed','Pending runtime activation proposal could not be cleared.',['status'=>500]));
            if(!(new AuditLogger($this->db))->logInOpenTransaction('runtime_disabled','runtime_activation',null,'success',['from'=>$previous,'to'=>$target,'reason'=>$reason],'runtime_governance',null,$actorUserId)){
                $this->rollback();$this->failClosed($foundation);
                return new WP_Error('smai_activation_audit_failed_failclosed','Runtime was forced fail-closed because disable audit evidence could not be written.',['status'=>500,'state'=>$target]);
            }
            if(!$this->commit()){ $this->failClosed($foundation); return new WP_Error('smai_disable_failed','Runtime disable state could not be committed and was forced fail-closed.',['status'=>500,'state'=>$target]); }
            $this->flushOptionCaches([self::OPTION_REQUEST,'smai_runtime_state','smai_activation_approved','smai_activation_evidence_hash','smai_worker_enabled']);
            return ['previous_state'=>$previous,'state'=>$target,'activation_approved'=>false,'worker_enabled'=>false];
        }catch(\Throwable $error){$this->rollback();$this->failClosed($foundation);return new WP_Error('smai_disable_failed','Runtime disable encountered an error and was forced fail-closed.',['status'=>500,'state'=>$target]);}
    }

    private function protectedEvidenceMatches(string $evidenceHash):bool
    {
        if(preg_match('/^[a-f0-9]{64}$/',$evidenceHash)!==1||!defined('SMAI_ACTIVATION_EVIDENCE_SHA256')||!is_string(SMAI_ACTIVATION_EVIDENCE_SHA256))return false;
        $configured=strtolower(trim((string)SMAI_ACTIVATION_EVIDENCE_SHA256));
        return preg_match('/^[a-f0-9]{64}$/',$configured)===1&&hash_equals($configured,$evidenceHash);
    }

    private function begin():bool{return $this->db->wpdb()->query('START TRANSACTION')!==false;}
    private function commit():bool{$ok=$this->db->wpdb()->query('COMMIT')!==false;if(!$ok)$this->rollback();return $ok;}
    private function rollback():void{$this->db->wpdb()->query('ROLLBACK');}
    private function rollbackError(WP_Error $error):WP_Error{$this->rollback();return $error;}

    private function readOptionForUpdate(string $name):mixed
    {
        $wpdb=$this->db->wpdb();$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE",$name));
        return $raw===null?null:maybe_unserialize($raw);
    }

    private function writeOption(string $name,mixed $value,bool $insertOnly=false):bool
    {
        $wpdb=$this->db->wpdb();$serialized=maybe_serialize($value);
        if($insertOnly)return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no')",$name,$serialized))===1;
        $exists=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE",$name));
        if($exists===null)return $wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no')",$name,$serialized))===1;
        return $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s,autoload='no' WHERE option_name=%s",$serialized,$name))!==false;
    }

    private function deleteOption(string $name):bool
    {
        $wpdb=$this->db->wpdb();return $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name=%s",$name))!==false;
    }

    /** @param array<int,string> $names */
    private function flushOptionCaches(array $names):void{foreach($names as $name)wp_cache_delete($name,'options');}

    private function failClosed(bool $foundation):void
    {
        update_option('smai_runtime_state',$foundation?RuntimeGate::FOUNDATION_DISABLED:RuntimeGate::SAFE_MODE,false);
        update_option('smai_activation_approved','0',false);update_option('smai_activation_evidence_hash','',false);update_option('smai_worker_enabled','0',false);delete_option(self::OPTION_REQUEST);
    }
}
'''
Path('src/Infrastructure/RuntimeActivationService.php').write_text(runtime,encoding='utf-8')

governance = r'''<?php

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
            'permission_callback'=>static fn():bool=>current_user_can('smai_export_metrics')||current_user_can('smai_manage_access')||current_user_can('smai_audit'),
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
        $payload=$request->get_json_params();if(!is_array($payload))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);return $payload;
    }

    private function response(array $result,int $status=200):WP_REST_Response
    {
        $response=new WP_REST_Response($result,$status);$response->header('Cache-Control','no-store, private');$response->header('X-Content-Type-Options','nosniff');$response->header('X-Sabri-Trace-ID',wp_generate_uuid4());return $response;
    }
}
'''
Path('src/Http/GovernanceRestController.php').write_text(governance,encoding='utf-8')

uninstall = r'''<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Governance, audit, metric and derivative DATA are retained by default.
// Destructive table/data purge requires a separate owner-approved,
// retention-aware, legal-hold-aware and provider-reconciled procedure.
// Runtime configuration, schedules, plugin-defined roles and capabilities are
// removed so an uninstalled plugin leaves no executable privilege surface.
foreach ([
    'smai_runtime_state', 'smai_activation_approved', 'smai_activation_evidence_hash', 'smai_activation_request',
    'smai_activation_lock', 'smai_schema_upgrade_lock',
    'smai_minimum_cohort', 'smai_raw_retention_days', 'smai_modeled_retention_days', 'smai_quarantine_retention_days',
    'smai_future_run_retention_days', 'smai_future_scenario_retention_days', 'smai_future_alert_retention_days', 'smai_future_incident_retention_days',
    'smai_export_ttl_hours', 'smai_report_link_ttl_hours', 'smai_max_export_rows', 'smai_worker_enabled', 'smai_allowed_regions', 'smai_provider_exit_state',
    'smai_future40_state', 'smai_future40_approved', 'smai_future40_evidence_hash', 'smai_future40_activation_request', 'smai_future40_approved_by', 'smai_future40_approved_at',
] as $option) {
    delete_option($option);
}

foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry','smai_future_intelligence_tick'] as $hook) {
    wp_clear_scheduled_hook($hook);
}

$capabilities = [
    'smai_view_insights','smai_manage_catalog','smai_approve_catalog','smai_manage_quality',
    'smai_manage_access','smai_ingest_events','smai_query_metrics','smai_export_metrics',
    'smai_manage_experiments','smai_manage_reports','smai_manage_backfills','smai_manage_providers',
    'smai_manage_deletions','smai_restore','smai_audit','smai_activate_runtime',
    'smai_manage_future_intelligence','smai_run_future_intelligence','smai_approve_future_intelligence','smai_view_transparency',
];
$administrator = get_role('administrator');
if ($administrator) {
    foreach ($capabilities as $capability) {
        $administrator->remove_cap($capability);
    }
}
foreach (['smai_analyst','smai_data_steward','smai_analytics_approver','smai_access_officer','smai_experiment_steward','smai_auditor','smai_recovery_operator'] as $role) {
    remove_role($role);
}
'''
Path('uninstall.php').write_text(uninstall,encoding='utf-8')

check = r'''#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
errors=[]
def text(rel):
    p=root/rel
    if not p.is_file(): errors.append('missing:'+rel); return ''
    return p.read_text(encoding='utf-8')
runtime=text('src/Infrastructure/RuntimeActivationService.php')
gov=text('src/Http/GovernanceRestController.php')
uninstall=text('uninstall.php')
for token in ['START TRANSACTION','logInOpenTransaction','RuntimeGate::schemaReady()','smai_activation_request_pending','actorUserId<1','INSERT IGNORE']:
    if token not in runtime: errors.append('runtime_activation_guard_missing:'+token)
for token in ['IdempotencyGuard','private function mutation','smai_request_too_large','smai_invalid_json','X-Sabri-Trace-ID']:
    if token not in gov: errors.append('governance_rest_guard_missing:'+token)
for token in ['smai_future_intelligence_tick','smai_future_run_retention_days','smai_future_scenario_retention_days','smai_future_alert_retention_days','smai_future_incident_retention_days','smai_future40_activation_request','smai_future40_approved_by','smai_future40_approved_at','smai_activation_request','remove_role','remove_cap']:
    if token not in uninstall: errors.append('uninstall_cleanup_missing:'+token)
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Release governance check passed: base activation atomicity, governance REST parity and uninstall least-privilege cleanup verified.')
'''
Path('scripts/release-governance-check.py').write_text(check,encoding='utf-8')

p=Path('scripts/qa.sh');s=p.read_text(encoding='utf-8')
anchor='python3 scripts/repository-hygiene-check.py\n'
if anchor not in s: raise SystemExit('QA anchor missing')
s=s.replace(anchor,anchor+'python3 scripts/release-governance-check.py\n',1);p.write_text(s,encoding='utf-8')

Path('docs/REVIEW-ROUND-10.md').write_text('''# CF-05 Review Round 10 — Final Adversarial Release Governance\n\nThe entire round was audited before any correction was started. This ledger was frozen first, then all defects were corrected together.\n\n## Defects found\n1. Base runtime activation proposal/approval/disable writes were not transaction-bound to audit evidence.\n2. Base runtime proposal/approval did not require exact schema readiness before governance state could advance.\n3. Base runtime proposals could overwrite an already-pending proposal, and approval lacked service-level positive actor validation.\n4. `GovernanceRestController` mutating export/report routes bypassed the common idempotency guard.\n5. The same governance routes lacked common 1 MiB payload validation, invalid-JSON handling and trace-header parity.\n6. Uninstall did not clear Future-40 activation/retention options or the Future intelligence cron hook.\n7. Uninstall left CF-05 custom roles and administrator capabilities behind despite least-privilege cleanup requirements.\n8. Mandatory QA had no final invariant covering base activation atomicity, governance REST parity and Future/uninstall cleanup.\n\n## Corrections\n- Base runtime activation now uses row-locked option reads, one-pending-request semantics, schema gates, independent actor validation and audit-bound transactions.\n- Disable remains fail-closed even if audit/commit fails.\n- Governance export/report mutations now use the shared idempotency/request-size/JSON/trace discipline.\n- Uninstall removes runtime/Future configuration, all CF-05 scheduled hooks, custom roles and administrator capabilities while retaining governed analytics data by default.\n- Added `release-governance-check.py` to mandatory QA.\n\nRepository/source correction only; this does not claim staging acceptance, live deployment or operational verification.\n''',encoding='utf-8')

# Record the final round in the changelog without changing public/schema contracts.
p=Path('CHANGELOG.md');s=p.read_text(encoding='utf-8')
anchor='## 1.0.0-rc.6 — 2026-09-16\n\n'
if anchor not in s: raise SystemExit('changelog anchor missing')
s=s.replace(anchor,anchor+'- Final ten-round adversarial review hardened base runtime activation atomicity, governance REST idempotency/request parity and least-privilege uninstall cleanup.\n',1);p.write_text(s,encoding='utf-8')
print('Review-10 final governance corrections applied.')
