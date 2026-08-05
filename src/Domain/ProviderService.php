<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use WP_Error;

final class ProviderService
{
    private Database $db;
    private AuditLogger $audit;
    private const TRANSITIONS=['proposed'=>['security_review'],'security_review'=>['approved'],'approved'=>['active'],'active'=>['draining','suspended'],'suspended'=>['active','draining'],'draining'=>['purge_pending'],'purge_pending'=>['retired']];

    public function __construct(Database $db){$this->db=$db;$this->audit=new AuditLogger($db);}

    /** @param array<string,mixed> $definition */
    public function register(array $definition,int $actorUserId):array|WP_Error
    {
        foreach(['provider_id','provider_version','region_code','capabilities','security','retention','exit'] as $key){if(!array_key_exists($key,$definition)){return new WP_Error('smai_invalid_provider','Provider definition is incomplete.',['status'=>400,'field'=>$key]);}}
        if($actorUserId<1||preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/',(string)$definition['provider_id'])!==1||preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)$definition['provider_version'])!==1||preg_match('/^[A-Z]{2}(?:-[A-Z0-9]{2,8})?$/',(string)$definition['region_code'])!==1||!is_array($definition['capabilities'])||!is_array($definition['security'])||!is_array($definition['retention'])||!is_array($definition['exit'])){return new WP_Error('smai_invalid_provider','Provider definition validation failed.',['status'=>400]);}
        if((new SensitiveValueDetector())->violations(['capabilities'=>$definition['capabilities'],'security'=>$definition['security'],'retention'=>$definition['retention'],'exit'=>$definition['exit']])!==[]){return new WP_Error('smai_provider_secrets_prohibited','Provider definitions may contain evidence references, never credentials or sensitive values.',['status'=>400]);}
        $allowedRegions=get_option('smai_allowed_regions',['PK']);if(!is_array($allowedRegions)||!in_array($definition['region_code'],$allowedRegions,true)){return new WP_Error('smai_provider_region_denied','Provider region is not approved.',['status'=>403]);}
        $canonical=['capabilities'=>$definition['capabilities'],'security'=>$definition['security'],'retention'=>$definition['retention'],'exit'=>$definition['exit']];
        foreach(['security','retention','exit'] as $section){if(!isset($canonical[$section]['evidence_hash'])||preg_match('/^[a-f0-9]{64}$/',(string)$canonical[$section]['evidence_hash'])!==1){return new WP_Error('smai_provider_evidence_required','Provider governance evidence hash is required.',['status'=>400,'section'=>$section]);}}
        $table=$this->db->table('providers');$existing=$this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT id,state,capabilities_json,security_json,retention_json,exit_json,row_version FROM `{$table}` WHERE provider_id=%s AND provider_version=%s",$definition['provider_id'],$definition['provider_version']),ARRAY_A);
        if(is_array($existing)){$old=['capabilities'=>Json::object((string)$existing['capabilities_json']),'security'=>Json::object((string)$existing['security_json']),'retention'=>Json::object((string)$existing['retention_json']),'exit'=>Json::object((string)$existing['exit_json'])];if(hash_equals(hash('sha256',Json::canonical($old)),hash('sha256',Json::canonical($canonical)))){return['id'=>(int)$existing['id'],'state'=>(string)$existing['state'],'row_version'=>(int)$existing['row_version'],'unchanged'=>true];}return new WP_Error('smai_provider_immutable','Existing provider version is immutable.',['status'=>409]);}
        $now=$this->db->now();$ok=$this->db->wpdb()->insert($table,['provider_id'=>$definition['provider_id'],'provider_version'=>$definition['provider_version'],'state'=>'proposed','region_code'=>$definition['region_code'],'capabilities_json'=>Json::canonical($definition['capabilities']),'security_json'=>Json::canonical($definition['security']),'retention_json'=>Json::canonical($definition['retention']),'exit_json'=>Json::canonical($definition['exit']),'row_version'=>1,'created_by'=>$actorUserId,'created_at'=>$now,'updated_at'=>$now]);
        return $ok===1?['id'=>(int)$this->db->wpdb()->insert_id,'state'=>'proposed','row_version'=>1]:new WP_Error('smai_provider_store_failed','Provider could not be stored.',['status'=>500]);
    }

    /** @param array<string,mixed> $evidence */
    public function transition(string $providerId,string $version,string $target,int $expectedVersion,int $actorUserId,array $evidence=[]):array|WP_Error
    {
        $table=$this->db->table('providers');$row=$this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE provider_id=%s AND provider_version=%s",$providerId,$version),ARRAY_A);
        if(!is_array($row)||(int)$row['row_version']!==$expectedVersion){return new WP_Error('smai_provider_stale','Provider is unavailable or stale.',['status'=>409]);}
        $from=(string)$row['state'];if(!in_array($target,self::TRANSITIONS[$from]??[],true)){return new WP_Error('smai_invalid_provider_transition','Provider transition is not allowed.',['status'=>409]);}
        if(in_array($target,['security_review','approved'],true)&&(int)$row['created_by']===$actorUserId){return new WP_Error('smai_separation_of_duties','Independent provider review is required.',['status'=>403]);}
        if($target==='active'&&(int)($row['approved_by']??0)===$actorUserId){return new WP_Error('smai_separation_of_duties','Provider activation requires an executor distinct from the approver.',['status'=>403]);}
        if((new SensitiveValueDetector())->violations($evidence)!==[]){return new WP_Error('smai_provider_evidence_sensitive','Provider transition evidence contains prohibited values.',['status'=>400]);}
        if(in_array($target,['approved','active','purge_pending','retired'],true)&&preg_match('/^[a-f0-9]{64}$/',(string)($evidence['evidence_hash']??''))!==1){return new WP_Error('smai_provider_transition_evidence_required','A verified evidence hash is required for this transition.',['status'=>400]);}
        if($target==='retired'){
            $status=$this->exitStatus($providerId);
            if($status['ready_to_retire']!==true){return new WP_Error('smai_provider_purge_incomplete','Provider cannot retire until governed data, active jobs and artifacts are purged or migrated.',['status'=>409,'exit_status'=>$status]);}
            if(!is_array($status['credential_revocation_evidence'])||preg_match('/^[a-f0-9]{64}$/',(string)($status['credential_revocation_evidence']['evidence_hash']??''))!==1){return new WP_Error('smai_provider_credential_revocation_unverified','Credential-revocation evidence is required.',['status'=>409]);}
        }
        $exit=Json::object((string)$row['exit_json']);if($evidence!==[]){$exit['last_transition_evidence']=$evidence;$exit['last_transition_target']=$target;$exit['last_transition_at']=gmdate('c');}
        $updated=$this->db->wpdb()->update($table,['state'=>$target,'approved_by'=>in_array($target,['approved','active'],true)?$actorUserId:$row['approved_by'],'exit_json'=>Json::canonical($exit),'row_version'=>$expectedVersion+1,'updated_at'=>$this->db->now()],['id'=>(int)$row['id'],'state'=>$from,'row_version'=>$expectedVersion]);
        if($updated!==1){return new WP_Error('smai_provider_conflict','Provider changed concurrently.',['status'=>409]);}
        $this->audit->log('provider_transition','provider',$providerId.'@'.$version,'success',['from'=>$from,'to'=>$target,'evidence_hash'=>$evidence['evidence_hash']??null],'provider_governance',null,$actorUserId);
        return['provider_id'=>$providerId,'provider_version'=>$version,'state'=>$target,'row_version'=>$expectedVersion+1];
    }

    public function exitStatus(string $providerId):array
    {
        $datasets=(int)$this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('datasets')}` WHERE provider_id=%s AND state NOT IN ('purged','retired')",$providerId));
        $jobs=(int)$this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('jobs')}` WHERE state IN ('queued','running','retrying') AND payload_json LIKE %s",'%'.$this->db->wpdb()->esc_like($providerId).'%'));
        $pendingDeletion=(int)$this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('deletion_reconciliations')}` WHERE store_name LIKE %s AND state<>'verified'",'provider:'.$this->db->wpdb()->esc_like($providerId).'@%'));
        $revocation=apply_filters('smai_provider_credential_revocation_evidence',null,$providerId);
        return['provider_id'=>$providerId,'remaining_datasets'=>$datasets,'active_jobs'=>$jobs,'pending_deletion_reconciliations'=>$pendingDeletion,'ready_to_retire'=>$datasets===0&&$jobs===0&&$pendingDeletion===0,'credential_revocation_evidence'=>$revocation,'generated_at'=>gmdate('c')];
    }
}
