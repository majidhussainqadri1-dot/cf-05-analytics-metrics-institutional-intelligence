#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

# Dataset registration + audit must be atomic.
p=root/'src/Domain/DatasetCatalog.php'; s=p.read_text(encoding='utf-8')
s=s.replace("        $now = $this->db->now();\n        $inserted = $wpdb->insert($table, [","        $now = $this->db->now();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_dataset_transaction_failed', 'Dataset registration transaction could not start.', ['status' => 500]);\n        }\n        $inserted = $wpdb->insert($table, [",1)
s=s.replace("        if ($inserted !== 1) {\n            return new WP_Error('smai_dataset_store_failed', 'Dataset could not be stored.', ['status' => 500]);\n        }","        if ($inserted !== 1) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_dataset_store_failed', 'Dataset could not be stored.', ['status' => 500]);\n        }",1)
s=s.replace("        if (!$this->audit->log(\n            'dataset_registered',","        if (!$this->audit->logInOpenTransaction(\n            'dataset_registered',",1)
s=s.replace("            $wpdb->delete($table, ['id' => $id, 'state' => 'draft', 'row_version' => 1]);\n            return new WP_Error('smai_dataset_audit_failed', 'Dataset registration was rolled back because audit evidence was unavailable.', ['status' => 503]);\n        }\n        return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];","            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_dataset_audit_failed', 'Dataset registration was rolled back because audit evidence was unavailable.', ['status' => 503]);\n        }\n        if ($wpdb->query('COMMIT') === false) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_dataset_commit_failed', 'Dataset registration could not be committed.', ['status' => 500]);\n        }\n        return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];",1)
p.write_text(s,encoding='utf-8')

# Quality rule creation and activation require durable audit evidence atomically.
p=root/'src/Domain/QualityService.php'; s=p.read_text(encoding='utf-8')
s=s.replace("        $now = $this->db->now();\n        $ok = $this->db->wpdb()->insert($table, [","        $now = $this->db->now();\n        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {return new WP_Error('smai_quality_transaction_failed', 'Quality-rule transaction could not start.', ['status'=>500]);}\n        $ok = $wpdb->insert($table, [",1)
s=s.replace("        if ($ok !== 1) {return new WP_Error('smai_quality_rule_store_failed', 'Quality rule could not be stored.', ['status' => 500]);}\n        return ['id' => (int) $this->db->wpdb()->insert_id, 'state' => 'draft', 'row_version' => 1, 'config_hash' => $hash];","        if ($ok !== 1) {$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_rule_store_failed', 'Quality rule could not be stored.', ['status' => 500]);}\n        $id=(int)$wpdb->insert_id;\n        if(!$this->audit->logInOpenTransaction('quality_rule_registered','quality_rule',(string)$id,'success',['rule_id'=>$rule['rule_id'],'rule_version'=>$rule['rule_version'],'dataset_ref'=>$rule['dataset_ref'],'config_hash'=>$hash],'data_quality',null,$actorUserId)){$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_audit_failed','Quality rule was not committed because audit evidence failed.',['status'=>503]);}\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_commit_failed','Quality rule could not be committed.',['status'=>500]);}\n        return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'config_hash' => $hash];",1)
old="""        $updated = $this->db->wpdb()->update($table, ['state' => 'active', 'approved_by' => $actorUserId, 'row_version' => $expectedVersion + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {return new WP_Error('smai_quality_rule_conflict', 'Quality rule changed concurrently.', ['status' => 409]);}
        $this->audit->log('quality_rule_activated', 'quality_rule', $ruleId . '@' . $version, 'success', ['dataset_ref' => $row['dataset_ref']], 'data_quality', null, $actorUserId);
        return ['rule_id' => $ruleId, 'rule_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];
"""
new="""        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_quality_transaction_failed','Quality-rule activation transaction could not start.',['status'=>500]);}
        $updated = $wpdb->update($table, ['state' => 'active', 'approved_by' => $actorUserId, 'row_version' => $expectedVersion + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_rule_conflict', 'Quality rule changed concurrently.', ['status' => 409]);}
        if(!$this->audit->logInOpenTransaction('quality_rule_activated', 'quality_rule', $ruleId . '@' . $version, 'success', ['dataset_ref' => $row['dataset_ref']], 'data_quality', null, $actorUserId)){$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_audit_failed','Quality-rule activation audit evidence failed.',['status'=>503]);}
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_quality_commit_failed','Quality-rule activation could not be committed.',['status'=>500]);}
        return ['rule_id' => $ruleId, 'rule_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];
"""
if old not in s: raise SystemExit('quality activation target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Pipeline must not report successful processing if derivative/lineage/checkpoint writes fail.
p=root/'src/Domain/PipelineService.php'; s=p.read_text(encoding='utf-8')
s=s.replace("            $this->lineage->link('event', $eventId, (string) $event['event_version'], 'build', (string) $build['build_uuid'], (string) $dataset['dataset_version'], (string) $dataset['owner_module'], (string) ($payload['job_uuid'] ?? null), defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null);","            if (!$this->lineage->link('event', $eventId, (string) $event['event_version'], 'build', (string) $build['build_uuid'], (string) $dataset['dataset_version'], (string) $dataset['owner_module'], isset($payload['job_uuid']) ? (string)$payload['job_uuid'] : null, defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null)) {\n                throw new \\RuntimeException('Dataset lineage evidence could not be recorded.');\n            }",1)
s=s.replace("        $this->db->wpdb()->update($this->db->table('events'), ['processed_at' => $this->db->now()], ['event_id' => $eventId]);\n        (new CheckpointService($this->db))->advance(","        if (!(new CheckpointService($this->db))->advance(",1)
s=s.replace("            ['event_id' => $eventId]\n        );\n        return ['event_id' => $eventId, 'projected_datasets' => $projected];","            ['event_id' => $eventId]\n        )) {\n            throw new \\RuntimeException('Pipeline checkpoint could not be advanced safely.');\n        }\n        if ($this->db->wpdb()->update($this->db->table('events'), ['processed_at' => $this->db->now()], ['event_id' => $eventId]) === false) {\n            throw new \\RuntimeException('Event processing state could not be recorded.');\n        }\n        return ['event_id' => $eventId, 'projected_datasets' => $projected];",1)
p.write_text(s,encoding='utf-8')

(root/'scripts/pipeline-quality-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
d=(r/'src/Domain/DatasetCatalog.php').read_text(encoding='utf-8')
q=(r/'src/Domain/QualityService.php').read_text(encoding='utf-8')
p=(r/'src/Domain/PipelineService.php').read_text(encoding='utf-8')
e=[]
if 'dataset_registered' not in d or 'logInOpenTransaction' not in d:e.append('dataset registration audit is not atomic')
if 'quality_rule_registered' not in q or "logInOpenTransaction('quality_rule_activated'" not in q:e.append('quality rule governance is not audit-atomic')
if "if (!$this->lineage->link" not in p:e.append('pipeline ignores lineage failure')
if "if (!(new CheckpointService($this->db))->advance" not in p:e.append('pipeline ignores checkpoint failure')
if e:print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Pipeline/quality invariants check passed.')
""",encoding='utf-8')
p=root/'scripts/qa.sh'; s=p.read_text(encoding='utf-8'); needle='python3 scripts/metric-privacy-invariants-check.py\n'
if 'pipeline-quality-invariants-check.py' not in s:
    if needle not in s: raise SystemExit('qa insertion missing')
    p.write_text(s.replace(needle,needle+'python3 scripts/pipeline-quality-invariants-check.py\n',1),encoding='utf-8')
(root/'docs/REVIEW-ROUND-14.md').write_text("""# Review Round 14 — Dataset, Pipeline, Checkpoint, Backfill, Lineage and Quality\n\nThe complete round was audited first; corrections began only after the defect ledger was frozen.\n\n## Confirmed defects\n1. Dataset registration and audit evidence were not transactionally atomic.\n2. Quality-rule registration had no audit record at all.\n3. Quality-rule activation changed state before a best-effort audit whose failure was ignored.\n4. Pipeline processing ignored lineage-write failure and could still continue as successful.\n5. Pipeline processing ignored checkpoint failure and marked the event processed regardless.\n\n## Corrections\nDataset and quality-rule governance writes now commit with audit evidence atomically; quality-rule creation has explicit audit evidence; pipeline processing fails/retries if lineage or checkpoint evidence cannot be persisted; permanent QA invariants were added.\n\nNo staging/live state is asserted.\n""",encoding='utf-8')
print('Review 14 corrections applied.')
