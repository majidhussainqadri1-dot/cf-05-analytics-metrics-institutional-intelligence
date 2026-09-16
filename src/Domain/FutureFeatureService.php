<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\FutureActivationService;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class FutureFeatureService
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->wpdb()->get_results('SELECT feature_id,state,config_json,row_version,approved_by,updated_at FROM `' . $this->db->table('future_features') . '`', ARRAY_A);
        $stored = [];
        foreach (is_array($rows) ? $rows : [] as $row) $stored[(string) $row['feature_id']] = $row;
        $out = [];
        foreach (FutureFeatureRegistry::all() as $id => $definition) {
            $row = $stored[$id] ?? null;
            $out[] = $definition + ['state' => is_array($row) ? (string) $row['state'] : 'disabled','configured' => is_array($row),'row_version' => is_array($row) ? (int) $row['row_version'] : 0,'approved_by' => is_array($row) && $row['approved_by'] !== null ? (int) $row['approved_by'] : null,'updated_at' => is_array($row) ? (string) $row['updated_at'] : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $config @return array<string,mixed>|WP_Error */
    public function configure(string $featureId, array $config, int $actorUserId, int $expectedRowVersion = 0): array|WP_Error
    {
        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);
        $definition = FutureFeatureRegistry::get($featureId);
        if ($definition === null) return new WP_Error('smai_future_unknown', 'Unknown future feature.', ['status' => 404]);
        $violations = (new SensitiveValueDetector())->violations($config);
        if ($violations !== []) return new WP_Error('smai_future_sensitive_input', 'Sensitive or restricted input is not allowed.', ['status' => 400, 'violations' => $violations]);
        $wpdb = $this->db->wpdb(); $table = $this->db->table('future_features'); $id = (string) $definition['feature_id'];
        $now = $this->db->now(); $json = Json::canonical($this->minimize($config)); $hash = hash('sha256', $json);
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future feature transaction could not start.', ['status' => 500]);
        try {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE feature_id=%s FOR UPDATE", $id), ARRAY_A);
            if (!is_array($existing)) {
                if ($expectedRowVersion !== 0) return $this->rollbackError(new WP_Error('smai_future_version_conflict', 'Future feature version conflict.', ['status' => 409]));
                $ok = $wpdb->insert($table, ['feature_id'=>$id,'title'=>Text::truncate((string)$definition['title'],190),'category'=>Text::truncate((string)$definition['category'],64),'phase'=>Text::truncate((string)$definition['phase'],32),'risk_class'=>Text::truncate((string)$definition['risk'],32),'state'=>'configured','config_json'=>$json,'config_hash'=>$hash,'requested_by'=>$actorUserId,'approved_by'=>null,'row_version'=>1,'created_at'=>$now,'updated_at'=>$now]);
                if ($ok !== 1) return $this->rollbackError(new WP_Error('smai_future_store_failed', 'Future feature configuration could not be stored.', ['status' => 500]));
                $version = 1;
            } else {
                $current = (int) $existing['row_version'];
                if ($expectedRowVersion !== $current) return $this->rollbackError(new WP_Error('smai_future_version_conflict', 'Future feature version conflict.', ['status' => 409, 'current_row_version' => $current]));
                if ((string) $existing['state'] === 'retired') return $this->rollbackError(new WP_Error('smai_future_retired', 'A retired future feature cannot be reconfigured without a versioned change-control replacement.', ['status' => 409]));
                $version = $current + 1;
                $ok = $wpdb->update($table, ['config_json'=>$json,'config_hash'=>$hash,'state'=>'configured','requested_by'=>$actorUserId,'approved_by'=>null,'row_version'=>$version,'updated_at'=>$now], ['feature_id'=>$id,'row_version'=>$current]);
                if ($ok !== 1) return $this->rollbackError(new WP_Error('smai_future_store_failed', 'Future feature configuration update failed.', ['status' => 409]));
            }
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future_feature_configured','future_feature',$id,'success',['row_version'=>$version,'config_hash'=>$hash],'future40_governance',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Configuration was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future feature configuration could not be committed.', ['status' => 500]);
            return ['feature_id'=>$id,'state'=>'configured','row_version'=>$version,'config_hash'=>$hash];
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_store_failed', 'Future feature configuration failed safely.', ['status' => 500]);
        }
    }

    /** @return array<string,mixed>|WP_Error */
    public function transition(string $featureId, string $action, string $reason, int $actorUserId, int $expectedRowVersion): array|WP_Error
    {
        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);
        $definition = FutureFeatureRegistry::get($featureId);
        if ($definition === null) return new WP_Error('smai_future_unknown', 'Unknown future feature.', ['status' => 404]);
        $reason=Text::truncate(trim($reason),500);if($reason==='')return new WP_Error('smai_future_reason_required','A governance reason is required.',['status'=>400]);
        $table=$this->db->table('future_features');$wpdb=$this->db->wpdb();$id=(string)$definition['feature_id'];
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future feature transaction could not start.', ['status' => 500]);
        try {
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE feature_id=%s FOR UPDATE",$id),ARRAY_A);
            if(!is_array($row))return $this->rollbackError(new WP_Error('smai_future_not_configured','Configure the future feature before changing lifecycle state.',['status'=>409]));
            $current=(string)$row['state'];
            $map=['approve'=>['configured'=>'approved','paused'=>'approved'],'activate'=>['approved'=>'active'],'pause'=>['active'=>'paused'],'disable'=>['configured'=>'disabled','approved'=>'disabled','active'=>'disabled','paused'=>'disabled'],'retire'=>['disabled'=>'retired','paused'=>'retired']];
            $next=$map[$action][$current]??null;
            if($next===null)return $this->rollbackError(new WP_Error('smai_future_invalid_transition','Future feature lifecycle transition is not allowed.',['status'=>409,'state'=>$current]));
            if((int)$row['row_version']!==$expectedRowVersion)return $this->rollbackError(new WP_Error('smai_future_version_conflict','Future feature version conflict.',['status'=>409,'current_row_version'=>(int)$row['row_version']]));
            if($action==='approve'&&(int)$row['requested_by']===$actorUserId)return $this->rollbackError(new WP_Error('smai_future_independence_required','Independent approval is required.',['status'=>409]));
            if($action==='activate'&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','A valid independent persisted approver is required before activation.',['status'=>409]));
            if($action==='activate'&&!FutureActivationService::isApproved())return $this->rollbackError(new WP_Error('smai_future_activation_gate','Future-40 activation evidence is not approved.',['status'=>409]));
            if($action==='activate'&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled for this environment.',['status'=>409]));
            $newVersion=$expectedRowVersion+1;$updates=['state'=>$next,'row_version'=>$newVersion,'updated_at'=>$this->db->now()];if($action==='approve')$updates['approved_by']=$actorUserId;
            $ok=$wpdb->update($table,$updates,['feature_id'=>$id,'row_version'=>$expectedRowVersion]);if($ok!==1)return $this->rollbackError(new WP_Error('smai_future_transition_failed','Future feature transition failed.',['status'=>409]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future_feature_'.$action,'future_feature',$id,'success',['from'=>$current,'to'=>$next,'reason'=>$reason,'row_version'=>$newVersion],'future40_governance',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Lifecycle transition was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future feature lifecycle transition could not be committed.', ['status' => 500]);
            return ['feature_id'=>$id,'from'=>$current,'to'=>$next,'row_version'=>$newVersion];
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_transition_failed', 'Future feature transition failed safely.', ['status' => 500]);
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function run(string $featureId, array $input, int $actorUserId, bool $dryRun = false): array|WP_Error
    {
        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);
        $definition=FutureFeatureRegistry::get($featureId);if($definition===null)return new WP_Error('smai_future_unknown','Unknown future feature.',['status'=>404]);
        $violations=(new SensitiveValueDetector())->violations($input);if($violations!==[])return new WP_Error('smai_future_sensitive_input','Sensitive or restricted input is not allowed.',['status'=>400,'violations'=>$violations]);
        if(!RuntimeGate::schemaReady())return new WP_Error('smai_future_schema_gate','CF-05 schema is not ready for governed Future-40 execution.',['status'=>409]);
        $id=(string)$definition['feature_id'];$wpdb=$this->db->wpdb();
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future feature run transaction could not start.', ['status' => 500]);
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT state,approved_by,requested_by FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',$id),ARRAY_A);
            if(!is_array($row))return $this->rollbackError(new WP_Error('smai_future_not_configured','Configure the future feature before any governed run, including dry-run.',['status'=>409]));
            $state=(string)$row['state'];
            if($state==='retired')return $this->rollbackError(new WP_Error('smai_future_retired','Retired future features cannot be executed.',['status'=>409]));
            if(!$dryRun&&$state!=='active')return $this->rollbackError(new WP_Error('smai_future_not_active','Future feature is not active. Use governed dry-run or complete activation gates.',['status'=>409]));
            if(!$dryRun&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','Active execution requires valid independent approval evidence.',['status'=>409]));
            if(!$dryRun&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled.',['status'=>409]));
            try{$result=Future40Engine::evaluate($id,$input);}catch(\InvalidArgumentException $error){return $this->rollbackError(new WP_Error('smai_future_invalid_input',$error->getMessage(),['status'=>400]));}
            $runUuid=Uuid::v4();$requestHash=hash('sha256',Json::canonical($this->minimize($input)));$resultJson=Json::canonical($this->minimize($result));
            $inserted=$wpdb->insert($this->db->table('future_runs'),['run_uuid'=>$runUuid,'feature_id'=>$id,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash,'result_json'=>$resultJson,'result_hash'=>hash('sha256',$resultJson),'actor_user_id'=>$actorUserId,'created_at'=>$this->db->now()]);
            if($inserted!==1)return $this->rollbackError(new WP_Error('smai_future_run_store_failed','Future feature result could not be stored.',['status'=>500]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future_feature_run','future_feature',$id,'success',['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash],'future40_analysis',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Future feature run was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future feature run could not be committed.', ['status' => 500]);
            return ['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active']+$result;
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_run_failed', 'Future feature run failed safely.', ['status' => 500]);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|WP_Error */
    public function createIncident(array $payload,int $actorUserId):array|WP_Error
    {
        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);
        $summary=Text::truncate(trim((string)($payload['summary']??'')),255);$severity=strtoupper((string)($payload['severity']??'SEV-4'));
        if($summary===''||!in_array($severity,['SEV-0','SEV-1','SEV-2','SEV-3','SEV-4'],true))return new WP_Error('smai_future_invalid_incident','Valid incident summary and severity are required.',['status'=>400]);
        if((new SensitiveValueDetector())->violations($payload)!==[])return new WP_Error('smai_future_sensitive_input','Sensitive incident payload is not allowed.',['status'=>400]);
        $uuid=Uuid::v4();$now=$this->db->now();$wpdb=$this->db->wpdb();
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Analytics incident transaction could not start.', ['status' => 500]);
        try {
            $inserted=$wpdb->insert($this->db->table('analytics_incidents'),['incident_uuid'=>$uuid,'severity'=>$severity,'state'=>'open','summary'=>$summary,'evidence_json'=>Json::canonical($this->minimize(is_array($payload['evidence']??null)?$payload['evidence']:[])),'owner_user_id'=>$actorUserId,'created_at'=>$now,'updated_at'=>$now]);
            if($inserted!==1)return $this->rollbackError(new WP_Error('smai_future_incident_store_failed','Analytics incident could not be stored.',['status'=>500]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('analytics_incident_created','analytics_incident',$uuid,'success',['severity'=>$severity],'future40_operations',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Incident was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Analytics incident could not be committed.', ['status' => 500]);
            return ['incident_uuid'=>$uuid,'severity'=>$severity,'state'=>'open'];
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_incident_store_failed', 'Analytics incident creation failed safely.', ['status'=>500]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function incidents():array
    {
        $rows=$this->db->wpdb()->get_results('SELECT incident_uuid,severity,state,summary,owner_user_id,created_at,updated_at FROM `'.$this->db->table('analytics_incidents').'` ORDER BY id DESC LIMIT 100',ARRAY_A);
        return is_array($rows)?$rows:[];
    }

    public function scheduledTick():void
    {
        if(!FutureActivationService::isApproved()||!RuntimeGate::queryEnabled())return;
        $wpdb=$this->db->wpdb();
        $active=$wpdb->get_col("SELECT feature_id FROM `{$this->db->table('future_features')}` WHERE state='active' AND feature_id IN ('CF05-FUT-036','CF05-FUT-037')");
        foreach(is_array($active)?$active:[] as $featureId){
            if(!$this->begin())continue;
            try {
                $state=$wpdb->get_var($wpdb->prepare('SELECT state FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',(string)$featureId));
                if($state!=='active'){ $this->rollback(); continue; }
                $uuid=Uuid::v4();
                $inserted=$wpdb->insert($this->db->table('intelligence_alerts'),['alert_uuid'=>$uuid,'feature_id'=>(string)$featureId,'severity'=>'info','state'=>'evidence_ready','title'=>'Scheduled Future-40 evaluation window','evidence_json'=>Json::canonical(['automatic_external_delivery'=>false]),'created_at'=>$this->db->now(),'updated_at'=>$this->db->now()]);
                if($inserted!==1){ $this->rollback(); continue; }
                if(!(new AuditLogger($this->db))->logInOpenTransaction('future_scheduled_evidence_created','intelligence_alert',$uuid,'success',['feature_id'=>(string)$featureId],'future40_operations',null,null,'system')){ $this->rollback(); continue; }
                if(!$this->commit())continue;
            } catch (\Throwable $error) {
                $this->rollback();
            }
        }
    }

    private function begin():bool
    {
        return $this->db->wpdb()->query('START TRANSACTION') !== false;
    }

    private function commit():bool
    {
        $ok=$this->db->wpdb()->query('COMMIT') !== false;
        if(!$ok)$this->rollback();
        return $ok;
    }

    private function rollback():void
    {
        $this->db->wpdb()->query('ROLLBACK');
    }

    private function rollbackError(WP_Error $error):WP_Error
    {
        $this->rollback();
        return $error;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function minimize(array $value):array
    {
        $out=[];foreach(array_slice($value,0,100,true) as $key=>$item)$out[Text::truncate((string)$key,100)]=$this->safeValue($item,0);return$out;
    }

    private function safeValue(mixed $value,int $depth):mixed
    {
        if($depth>5)return '[truncated]';if(is_string($value))return Text::truncate(wp_strip_all_tags($value),1000);if(is_scalar($value)||$value===null)return$value;
        if(is_array($value)){$out=[];foreach(array_slice($value,0,100,true) as $key=>$item)$out[(string)$key]=$this->safeValue($item,$depth+1);return$out;}return '[unsupported]';
    }
}
