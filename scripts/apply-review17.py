#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

def load(path): return (root/path).read_text(encoding='utf-8')
def save(path,s): (root/path).write_text(s,encoding='utf-8')
def rep(s,old,new,count=1,label=''):
    if old not in s: raise SystemExit('target missing '+label+': '+old[:120])
    return s.replace(old,new,count)

# Deletion service: caller-transaction audit + atomic completion evidence.
p='src/Domain/DeletionService.php'; s=load(p)
s=s.replace("$this->audit->log('analytics_deletion_requested'","$this->audit->logInOpenTransaction('analytics_deletion_requested'",1)
s=rep(s,"        $wpdb->query('COMMIT');\n        return ['job_uuid' => $uuid, 'state' => 'requested', 'duplicate' => false, 'worker_job' => $worker];",
"        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_deletion_commit_failed','Deletion request could not be committed.',['status'=>500]); }\n        return ['job_uuid' => $uuid, 'state' => 'requested', 'duplicate' => false, 'worker_job' => $worker];",1,'deletion request commit')
old="""        if ($wpdb->update($table, ['state' => 'completed', 'result_json' => Json::encode($reconciliation), 'completed_at' => $now, 'next_retry_at' => null, 'updated_at' => $now], ['id' => (int) $job['id'], 'state' => 'running']) !== 1
            || !$this->audit->log('analytics_deletion_completed', 'deletion_job', $uuid, 'success', ['stores' => array_keys($reconciliation)], 'privacy_rights', null, (int) ($payload['actor_user_id'] ?? 0))) {
            $this->retry($job, $reconciliation, ['completion_evidence']);
            throw new \\RuntimeException('Deletion completion evidence could not be committed.');
        }
"""
new="""        if ($wpdb->query('START TRANSACTION') === false) {
            $this->retry($job, $reconciliation, ['completion_transaction']);
            throw new \\RuntimeException('Deletion completion transaction could not start.');
        }
        $completed = $wpdb->update($table, ['state' => 'completed', 'result_json' => Json::encode($reconciliation), 'completed_at' => $now, 'next_retry_at' => null, 'updated_at' => $now], ['id' => (int) $job['id'], 'state' => 'running']);
        $audited = $completed === 1 && $this->audit->logInOpenTransaction('analytics_deletion_completed', 'deletion_job', $uuid, 'success', ['stores' => array_keys($reconciliation)], 'privacy_rights', null, (int) ($payload['actor_user_id'] ?? 0));
        if (!$audited || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            $this->retry($job, $reconciliation, ['completion_evidence']);
            throw new \\RuntimeException('Deletion completion evidence could not be committed.');
        }
"""
s=rep(s,old,new,1,'deletion completion')
save(p,s)

# Provider governance: registration/transition must be audited atomically and approver provenance preserved.
p='src/Domain/ProviderService.php'; s=load(p)
old="$now=$this->db->now();$ok=$this->db->wpdb()->insert($table,['provider_id'=>$definition['provider_id'],'provider_version'=>$definition['provider_version'],'state'=>'proposed','region_code'=>$definition['region_code'],'capabilities_json'=>Json::canonical($definition['capabilities']),'security_json'=>Json::canonical($definition['security']),'retention_json'=>Json::canonical($definition['retention']),'exit_json'=>Json::canonical($definition['exit']),'row_version'=>1,'created_by'=>$actorUserId,'created_at'=>$now,'updated_at'=>$now]);\n        return $ok===1?['id'=>(int)$this->db->wpdb()->insert_id,'state'=>'proposed','row_version'=>1]:new WP_Error('smai_provider_store_failed','Provider could not be stored.',['status'=>500]);"
new="$now=$this->db->now();$wpdb=$this->db->wpdb();if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_provider_transaction_failed','Provider transaction could not start.',['status'=>500]);}$ok=$wpdb->insert($table,['provider_id'=>$definition['provider_id'],'provider_version'=>$definition['provider_version'],'state'=>'proposed','region_code'=>$definition['region_code'],'capabilities_json'=>Json::canonical($definition['capabilities']),'security_json'=>Json::canonical($definition['security']),'retention_json'=>Json::canonical($definition['retention']),'exit_json'=>Json::canonical($definition['exit']),'row_version'=>1,'created_by'=>$actorUserId,'created_at'=>$now,'updated_at'=>$now]);\n        if($ok!==1){$wpdb->query('ROLLBACK');return new WP_Error('smai_provider_store_failed','Provider could not be stored.',['status'=>500]);}$id=(int)$wpdb->insert_id;if(!$this->audit->logInOpenTransaction('provider_registered','provider',(string)$definition['provider_id'].'@'.(string)$definition['provider_version'],'success',['region_code'=>$definition['region_code']],'provider_governance',null,$actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_provider_audit_failed','Provider was not committed because audit evidence failed.',['status'=>503]); }if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_provider_commit_failed','Provider could not be committed.',['status'=>500]);}return['id'=>$id,'state'=>'proposed','row_version'=>1];"
s=rep(s,old,new,1,'provider register')
old="$updated=$this->db->wpdb()->update($table,['state'=>$target,'approved_by'=>in_array($target,['approved','active'],true)?$actorUserId:$row['approved_by'],'exit_json'=>Json::canonical($exit),'row_version'=>$expectedVersion+1,'updated_at'=>$this->db->now()],['id'=>(int)$row['id'],'state'=>$from,'row_version'=>$expectedVersion]);\n        if($updated!==1){return new WP_Error('smai_provider_conflict','Provider changed concurrently.',['status'=>409]);}\n        $this->audit->log('provider_transition','provider',$providerId.'@'.$version,'success',['from'=>$from,'to'=>$target,'evidence_hash'=>$evidence['evidence_hash']??null],'provider_governance',null,$actorUserId);\n        return['provider_id'=>$providerId,'provider_version'=>$version,'state'=>$target,'row_version'=>$expectedVersion+1];"
new="$wpdb=$this->db->wpdb();if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_provider_transaction_failed','Provider transition transaction could not start.',['status'=>500]);}$approvedBy=$target==='approved'?$actorUserId:$row['approved_by'];$updated=$wpdb->update($table,['state'=>$target,'approved_by'=>$approvedBy,'exit_json'=>Json::canonical($exit),'row_version'=>$expectedVersion+1,'updated_at'=>$this->db->now()],['id'=>(int)$row['id'],'state'=>$from,'row_version'=>$expectedVersion]);\n        if($updated!==1){$wpdb->query('ROLLBACK');return new WP_Error('smai_provider_conflict','Provider changed concurrently.',['status'=>409]);}\n        if(!$this->audit->logInOpenTransaction('provider_transition','provider',$providerId.'@'.$version,'success',['from'=>$from,'to'=>$target,'evidence_hash'=>$evidence['evidence_hash']??null],'provider_governance',null,$actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_provider_audit_failed','Provider transition was not committed because audit evidence failed.',['status'=>503]); }if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_provider_commit_failed','Provider transition could not be committed.',['status'=>500]);}\n        return['provider_id'=>$providerId,'provider_version'=>$version,'state'=>$target,'row_version'=>$expectedVersion+1];"
s=rep(s,old,new,1,'provider transition')
save(p,s)

# Restore evidence: atomic state/audit, correct experiment deletion verification, post-commit signal only.
p='src/Domain/RestoreService.php'; s=load(p)
s=rep(s,"        $ok = $this->db->wpdb()->insert($this->db->table('restore_points'), [",
"        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_restore_transaction_failed','Restore evidence transaction could not start.',['status'=>500]);}\n        $ok = $wpdb->insert($this->db->table('restore_points'), [",1,'restore record start')
s=rep(s,"        if ($ok !== 1) {\n            return new WP_Error('smai_restore_point_store_failed', 'Restore point could not be stored.', ['status' => 500]);\n        }\n        $this->audit->log('restore_point_recorded', 'restore_point', $uuid, 'success', ['code_sha' => $codeSha, 'catalog_hash' => $catalogHash], 'disaster_recovery', null, $actorUserId);\n        return ['restore_uuid' => $uuid, 'state' => 'recorded', 'catalog_hash' => $catalogHash, 'checkpoint_hash' => hash('sha256', Json::canonical($checkpoints))];",
"        if ($ok !== 1) { $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_restore_point_store_failed', 'Restore point could not be stored.', ['status' => 500]);\n        }\n        if(!$this->audit->logInOpenTransaction('restore_point_recorded', 'restore_point', $uuid, 'success', ['code_sha' => $codeSha, 'catalog_hash' => $catalogHash], 'disaster_recovery', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_restore_audit_failed','Restore point was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_restore_commit_failed','Restore point could not be committed.',['status'=>500]);}\n        return ['restore_uuid' => $uuid, 'state' => 'recorded', 'catalog_hash' => $catalogHash, 'checkpoint_hash' => hash('sha256', Json::canonical($checkpoints))];",1,'restore record end')
s=rep(s,"        $verified = !in_array(false, $checks, true);\n        $this->db->wpdb()->update($table, ['state' => $verified ? 'verified' : 'failed', 'verified_by' => $actorUserId, 'verified_at' => $this->db->now()], ['id' => (int) $point['id']]);\n        $this->audit->log('warehouse_restore_verified', 'restore_point', $uuid, $verified ? 'success' : 'failed', $checks, 'disaster_recovery', null, $actorUserId);\n        do_action('smai_restore_verification_completed', ['restore_uuid' => $uuid, 'verified' => $verified, 'checks' => $checks]);\n        return ['restore_uuid' => $uuid, 'state' => $verified ? 'verified' : 'failed', 'checks' => $checks];",
"        $verified = !in_array(false, $checks, true);\n        $wpdb=$this->db->wpdb();if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_restore_transaction_failed','Restore verification transaction could not start.',['status'=>500]);}\n        $updated=$wpdb->update($table, ['state' => $verified ? 'verified' : 'failed', 'verified_by' => $actorUserId, 'verified_at' => $this->db->now()], ['id' => (int) $point['id']]);\n        if($updated!==1 || !$this->audit->logInOpenTransaction('warehouse_restore_verified', 'restore_point', $uuid, $verified ? 'success' : 'failed', $checks, 'disaster_recovery', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_restore_audit_failed','Restore verification evidence could not be committed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_restore_commit_failed','Restore verification could not be committed.',['status'=>500]);}\n        do_action('smai_restore_verification_completed', ['restore_uuid' => $uuid, 'verified' => $verified, 'checks' => $checks]);\n        return ['restore_uuid' => $uuid, 'state' => $verified ? 'verified' : 'failed', 'checks' => $checks];",1,'restore verify')
s=s.replace("['experiment_facts','subject_ref']","['experiment_facts','deletion_key']",1)
save(p,s)

# Retention must not silently continue after partial database failure; it is one audited transaction.
p='src/Infrastructure/RetentionRunner.php'; s=load(p)
s=s.replace("        $wpdb->query($wpdb->prepare(","        $this->mustQuery($wpdb, $wpdb->prepare(")
s=s.replace("            $wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid]);","            if ($wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid]) === false) { throw new \\RuntimeException('Retention export payload purge failed.'); }")
first="""        $this->mustQuery($wpdb, $wpdb->prepare(
            \"DELETE FROM `{$this->db->table('events')}` WHERE (expires_at IS NOT NULL AND expires_at<%s) OR (expires_at IS NULL AND created_at<%s)\",
"""
if first not in s: raise SystemExit('retention first query target missing')
s=s.replace(first,"""        if ($wpdb->query('START TRANSACTION') === false) { throw new \\RuntimeException('Retention transaction could not start.'); }
        try {
        $this->mustQuery($wpdb, $wpdb->prepare(
            \"DELETE FROM `{$this->db->table('events')}` WHERE (expires_at IS NOT NULL AND expires_at<%s) OR (expires_at IS NULL AND created_at<%s)\",
""",1)
last="""        $this->mustQuery($wpdb, $wpdb->prepare(\"DELETE FROM `{$this->db->table('research_workspaces')}` WHERE state IN ('expired','revoked') AND updated_at<%s\", $modelCutoff));
    }
}
"""
newlast="""        $this->mustQuery($wpdb, $wpdb->prepare(\"DELETE FROM `{$this->db->table('research_workspaces')}` WHERE state IN ('expired','revoked') AND updated_at<%s\", $modelCutoff));
        $audit = new AuditLogger($this->db);
        if (!$audit->logInOpenTransaction('analytics_retention_completed','retention_run',gmdate('Y-m-d'),'success',['event_cutoff'=>$eventCutoff,'model_cutoff'=>$modelCutoff,'quarantine_cutoff'=>$quarantineCutoff],'retention',null,null,'system')) { throw new \\RuntimeException('Retention audit evidence failed.'); }
        if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Retention commit failed.'); }
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw new \\RuntimeException('Retention run failed safely.', 0, $error);
        }
    }

    private function mustQuery($wpdb, string $sql): int
    {
        $result = $wpdb->query($sql);
        if ($result === false) { throw new \\RuntimeException('Retention database operation failed.'); }
        return (int) $result;
    }
}
"""
s=rep(s,last,newlast,1,'retention end')
save(p,s)

(root/'scripts/deletion-retention-restore-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
d=(r/'src/Domain/DeletionService.php').read_text(encoding='utf-8')
p=(r/'src/Domain/ProviderService.php').read_text(encoding='utf-8')
x=(r/'src/Domain/RestoreService.php').read_text(encoding='utf-8')
t=(r/'src/Infrastructure/RetentionRunner.php').read_text(encoding='utf-8')
e=[]
if "audit->log('analytics_deletion_requested'" in d or "audit->log('analytics_deletion_completed'" in d:e.append('deletion governance audit is not atomic')
if 'provider_registered' not in p or "audit->log('provider_transition'" in p:e.append('provider governance audit is incomplete/non-atomic')
if "in_array($target,['approved','active'],true)?$actorUserId" in p:e.append('provider activation overwrites independent approver provenance')
if "['experiment_facts','subject_ref']" in x:e.append('restore deletion verification checks the wrong experiment-fact column')
if "audit->log('restore_point_recorded'" in x or "audit->log('warehouse_restore_verified'" in x:e.append('restore evidence audit is not atomic')
if 'analytics_retention_completed' not in t or 'mustQuery' not in t:e.append('retention can fail partially without durable evidence')
if e:print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Deletion/retention/provider/restore invariants check passed.')
""",encoding='utf-8')
qa=root/'scripts/qa.sh'; q=qa.read_text(encoding='utf-8'); needle='python3 scripts/experiment-governance-invariants-check.py\n'
if 'deletion-retention-restore-invariants-check.py' not in q:
    if needle not in q: raise SystemExit('qa insertion target missing')
    qa.write_text(q.replace(needle,needle+'python3 scripts/deletion-retention-restore-invariants-check.py\n',1),encoding='utf-8')

(root/'docs/REVIEW-ROUND-17.md').write_text("""# Review Round 17 — Deletion, Retention, Provider Exit and Restore\n\nThe complete privacy-deletion, retention, provider-exit and restore/reproducibility surface was audited before corrections began.\n\n## Confirmed defects\n1. Deletion request/completion audit evidence was not reliably part of the caller transaction; completion could persist before audit failure and then become difficult to retry correctly.\n2. Provider registration had no audit record, provider transitions used best-effort audit after mutation, and activation overwrote the identity of the independent approver.\n3. Restore-point recording and verification mutated state with best-effort audit; the verification-completed action could fire even when durable audit/state evidence failed.\n4. Restore deletion verification checked `experiment_facts.subject_ref` while deletion itself is keyed on `experiment_facts.deletion_key`, so restored deleted experiment facts could escape the verification test.\n5. Retention ignored database-operation failures and had no durable run-level audit evidence, allowing silent partial retention.\n\n## Corrections\nDeletion completion/request evidence is atomic; provider governance is audit-atomic and preserves approver provenance; restore record/verify is fail-closed and checks the correct deletion key; post-restore signals occur after commit; retention runs transactionally, fail on any database error and append system audit evidence; permanent QA invariants were added.\n\nNo staging/live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 17 corrections applied.')
