<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class RuntimeActivationService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db){$this->db=$db;$this->audit=new AuditLogger($db);}

    public function propose(string $state,string $evidenceHash,string $reason,int $actorUserId):array|WP_Error
    {
        if($actorUserId<1||!in_array($state,[RuntimeGate::CATALOG_ONLY,RuntimeGate::STAGING_ACTIVE,RuntimeGate::PRODUCTION_ACTIVE],true)||preg_match('/^[a-f0-9]{64}$/',strtolower($evidenceHash))!==1||strlen(trim($reason))<12){return new WP_Error('smai_invalid_activation_proposal','Runtime activation proposal is invalid.',['status'=>400]);}
        if(!defined('SMAI_ACTIVATION_EVIDENCE_SHA256')||!is_string(SMAI_ACTIVATION_EVIDENCE_SHA256)||!hash_equals(strtolower(SMAI_ACTIVATION_EVIDENCE_SHA256),strtolower($evidenceHash))){return new WP_Error('smai_activation_evidence_mismatch','Activation evidence does not match the protected configuration.',['status'=>409]);}
        $request=['request_uuid'=>Uuid::v4(),'state'=>$state,'evidence_hash'=>strtolower($evidenceHash),'reason'=>Text::truncate(trim(wp_strip_all_tags($reason)),500),'proposed_by'=>$actorUserId,'proposed_at'=>gmdate('c'),'request_hash'=>''];
        $request['request_hash']=hash('sha256',Json::canonical(array_diff_key($request,['request_hash'=>true])));
        update_option('smai_activation_request',$request,false);
        $this->audit->log('runtime_activation_proposed','runtime_activation',$request['request_uuid'],'success',['state'=>$state,'request_hash'=>$request['request_hash']],'runtime_governance',null,$actorUserId);
        return['request_uuid'=>$request['request_uuid'],'state'=>'proposed','target_state'=>$state,'request_hash'=>$request['request_hash']];
    }

    public function approve(string $requestHash,int $actorUserId):array|WP_Error
    {
        $request=get_option('smai_activation_request');
        if(!is_array($request)||preg_match('/^[a-f0-9]{64}$/',$requestHash)!==1||!hash_equals((string)($request['request_hash']??''),$requestHash)){return new WP_Error('smai_activation_request_stale','Activation request is unavailable or stale.',['status'=>409]);}
        if((int)($request['proposed_by']??0)===$actorUserId){return new WP_Error('smai_separation_of_duties','Activation requires an independent approver.',['status'=>403]);}
        $state=(string)($request['state']??'');$environment=function_exists('wp_get_environment_type')?wp_get_environment_type():'unknown';
        if($state===RuntimeGate::PRODUCTION_ACTIVE&&$environment!=='production'){return new WP_Error('smai_activation_environment_mismatch','Production activation is permitted only in the production environment.',['status'=>409]);}
        if($state===RuntimeGate::STAGING_ACTIVE&&!in_array($environment,['staging','development','local'],true)){return new WP_Error('smai_activation_environment_mismatch','Staging activation is permitted only in a non-production staging-compatible environment.',['status'=>409]);}
        $evidence=(string)($request['evidence_hash']??'');
        if(!defined('SMAI_ACTIVATION_EVIDENCE_SHA256')||!hash_equals(strtolower((string)SMAI_ACTIVATION_EVIDENCE_SHA256),$evidence)){return new WP_Error('smai_activation_evidence_mismatch','Protected activation evidence changed.',['status'=>409]);}
        update_option('smai_runtime_state',$state,false);update_option('smai_activation_evidence_hash',$evidence,false);update_option('smai_activation_approved','1',false);update_option('smai_worker_enabled',in_array($state,[RuntimeGate::STAGING_ACTIVE,RuntimeGate::PRODUCTION_ACTIVE],true)?'1':'0',false);delete_option('smai_activation_request');
        $this->audit->log('runtime_activation_approved','runtime_activation',(string)$request['request_uuid'],'success',['state'=>$state,'environment'=>$environment,'request_hash'=>$requestHash],'runtime_governance',null,$actorUserId);
        return['state'=>$state,'activation_approved'=>RuntimeGate::activationApproved(),'worker_enabled'=>RuntimeGate::workerEnabled(),'environment'=>$environment];
    }

    public function disable(string $reason,int $actorUserId,bool $foundation=false):array|WP_Error
    {
        if($actorUserId<1||strlen(trim($reason))<8){return new WP_Error('smai_disable_reason_required','A meaningful runtime-disable reason is required.',['status'=>400]);}
        $previous=RuntimeGate::state();$target=$foundation?RuntimeGate::FOUNDATION_DISABLED:RuntimeGate::SAFE_MODE;
        update_option('smai_runtime_state',$target,false);update_option('smai_activation_approved','0',false);update_option('smai_worker_enabled','0',false);delete_option('smai_activation_request');
        $this->audit->log('runtime_disabled','runtime_activation',null,'success',['from'=>$previous,'to'=>$target,'reason'=>Text::truncate(trim(wp_strip_all_tags($reason)),500)],'runtime_governance',null,$actorUserId);
        return['previous_state'=>$previous,'state'=>$target,'activation_approved'=>false,'worker_enabled'=>false];
    }
}
