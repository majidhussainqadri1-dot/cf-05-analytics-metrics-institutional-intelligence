#!/usr/bin/env python3
from pathlib import Path

p=Path('src/Domain/FutureFeatureService.php')
s=p.read_text(encoding='utf-8')
old="""            $row=$wpdb->get_row($wpdb->prepare('SELECT state,approved_by,requested_by FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',$id),ARRAY_A);
            if(!is_array($row))return $this->rollbackError(new WP_Error('smai_future_not_configured','Configure the future feature before any governed run, including dry-run.',['status'=>409]));
            $state=(string)$row['state'];
            if($state==='retired')return $this->rollbackError(new WP_Error('smai_future_retired','Retired future features cannot be executed.',['status'=>409]));
            if(!$dryRun&&$state!=='active')return $this->rollbackError(new WP_Error('smai_future_not_active','Future feature is not active. Use governed dry-run or complete activation gates.',['status'=>409]));
            if(!$dryRun&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','Active execution requires valid independent approval evidence.',['status'=>409]));
            if(!$dryRun&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled.',['status'=>409]));
            try{$result=Future40Engine::evaluate($id,$input);}catch(\\InvalidArgumentException $error){return $this->rollbackError(new WP_Error('smai_future_invalid_input',$error->getMessage(),['status'=>400]));}
            $runUuid=Uuid::v4();$requestHash=hash('sha256',Json::canonical($this->minimize($input)));$resultJson=Json::canonical($this->minimize($result));
            $inserted=$wpdb->insert($this->db->table('future_runs'),['run_uuid'=>$runUuid,'feature_id'=>$id,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash,'result_json'=>$resultJson,'result_hash'=>hash('sha256',$resultJson),'actor_user_id'=>$actorUserId,'created_at'=>$this->db->now()]);
            if($inserted!==1)return $this->rollbackError(new WP_Error('smai_future_run_store_failed','Future feature result could not be stored.',['status'=>500]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future_feature_run','future_feature',$id,'success',['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash],'future40_analysis',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Future feature run was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future feature run could not be committed.', ['status' => 500]);
            return ['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active']+$result;"""
new="""            $row=$wpdb->get_row($wpdb->prepare('SELECT state,approved_by,requested_by,row_version,config_hash FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',$id),ARRAY_A);
            if(!is_array($row))return $this->rollbackError(new WP_Error('smai_future_not_configured','Configure the future feature before any governed run, including dry-run.',['status'=>409]));
            $state=(string)$row['state'];
            if($state==='retired')return $this->rollbackError(new WP_Error('smai_future_retired','Retired future features cannot be executed.',['status'=>409]));
            if(!$dryRun&&$state!=='active')return $this->rollbackError(new WP_Error('smai_future_not_active','Future feature is not active. Use governed dry-run or complete activation gates.',['status'=>409]));
            if(!$dryRun&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','Active execution requires valid independent approval evidence.',['status'=>409]));
            if(!$dryRun&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled.',['status'=>409]));
            $artifactStore=new FutureArtifactStore($this->db);
            $prepared=$artifactStore->prepareInput($id,$input,$dryRun);
            if(is_wp_error($prepared))return $this->rollbackError($prepared);
            $executionInput=$prepared;
            try{$result=Future40Engine::evaluate($id,$executionInput);}catch(\\InvalidArgumentException $error){return $this->rollbackError(new WP_Error('smai_future_invalid_input',$error->getMessage(),['status'=>400]));}
            $artifacts=$artifactStore->persist($id,$executionInput,$result,$actorUserId,$dryRun);
            if(is_wp_error($artifacts))return $this->rollbackError($artifacts);
            $governance=['feature_row_version'=>(int)$row['row_version'],'config_hash'=>(string)$row['config_hash'],'schema_version'=>defined('SMAI_SCHEMA_VERSION')?SMAI_SCHEMA_VERSION:null,'contract_version'=>defined('SMAI_CONTRACT_VERSION')?SMAI_CONTRACT_VERSION:null];
            $storedResult=$result+['governance'=>$governance,'artifacts'=>$artifacts];
            $runUuid=Uuid::v4();$requestHash=hash('sha256',Json::canonical($this->minimize($executionInput)));$resultJson=Json::canonical($this->minimize($storedResult));
            $inserted=$wpdb->insert($this->db->table('future_runs'),['run_uuid'=>$runUuid,'feature_id'=>$id,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash,'result_json'=>$resultJson,'result_hash'=>hash('sha256',$resultJson),'actor_user_id'=>$actorUserId,'created_at'=>$this->db->now()]);
            if($inserted!==1)return $this->rollbackError(new WP_Error('smai_future_run_store_failed','Future feature result could not be stored.',['status'=>500]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future_feature_run','future_feature',$id,'success',['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active','request_hash'=>$requestHash,'feature_row_version'=>(int)$row['row_version'],'config_hash'=>(string)$row['config_hash'],'artifacts'=>$artifacts],'future40_analysis',null,$actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Future feature run was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future feature run could not be committed.', ['status' => 500]);
            return ['run_uuid'=>$runUuid,'mode'=>$dryRun?'dry_run':'active','governance'=>$governance,'artifacts'=>$artifacts]+$result;"""
if old not in s: raise SystemExit('missing FutureFeatureService run anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')

q=Path('scripts/future40-check.py')
t=q.read_text(encoding='utf-8')
anchor="""if service.count('smai_future_actor_required') < 4:
    errors.append('review5_service_actor_validation_incomplete')
"""
addition="""if service.count('smai_future_actor_required') < 4:
    errors.append('review5_service_actor_validation_incomplete')
# Review-6 reproducibility/stateful artifact invariants.
artifact=text('src/Domain/FutureArtifactStore.php')
for token in ['FutureArtifactStore','feature_row_version','config_hash','schema_version','contract_version']:
    if token not in service: errors.append(f'review6_run_binding_missing:{token}')
for token in ['CF05-FUT-021','scenario_models','CF05-FUT-025','privacy_budgets','CF05-FUT-029','research_workspaces','CF05-FUT-038','transparency_records','FOR UPDATE','budget_row_version']:
    if token not in artifact: errors.append(f'review6_artifact_persistence_missing:{token}')
"""
if anchor not in t: raise SystemExit('missing future40-check review5 anchor')
q.write_text(t.replace(anchor,addition,1),encoding='utf-8')
print('Review-6 persistence bindings and regression gates applied.')
