<?php

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
        if($actorUserId<1||!user_can($actorUserId,'smai_activate_runtime'))return new WP_Error('smai_activation_forbidden','Runtime activation operation is not authorized.',['status'=>403]);
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
        if($actorUserId<1||!user_can($actorUserId,'smai_activate_runtime'))return new WP_Error('smai_activation_forbidden','Runtime activation operation is not authorized.',['status'=>403]);
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
        if($actorUserId<1||!user_can($actorUserId,'smai_activate_runtime'))return new WP_Error('smai_activation_forbidden','Runtime activation operation is not authorized.',['status'=>403]);
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
