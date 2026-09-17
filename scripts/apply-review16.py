#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=root/'src/Domain/ExperimentService.php'
s=p.read_text(encoding='utf-8')

def rep(old,new,count=1):
    global s
    if old not in s:
        raise SystemExit('target missing: '+old[:120])
    s=s.replace(old,new,count)

# Any experiment mutation inside an existing transaction must append audit evidence
# to that same transaction, never start a nested one.
for action in [
    'experiment_created','experiment_transition','experiment_assignment_recorded',
    'experiment_analysis_created','experiment_analysis_published'
]:
    s=s.replace(f"$this->audit->log('{action}'",f"$this->audit->logInOpenTransaction('{action}'")

# Guardrail signal must only be emitted after durable analysis persistence.
rep("            do_action('smai_experiment_guardrail_breached', ['experiment_uuid' => $uuid, 'analysis_version' => $analysisVersion, 'guardrails' => $result['guardrails']]);\n","")
rep("        $wpdb->query('COMMIT');\n        return ['analysis_uuid' => $analysisUuid, 'status' => 'draft', 'row_version' => 1, 'result' => $result];",
"        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_analysis_commit_failed', 'Analysis could not be committed.', ['status'=>500]); }\n        if ($result['guardrail_breached']) {\n            do_action('smai_experiment_guardrail_breached', ['experiment_uuid' => $uuid, 'analysis_version' => $analysisVersion, 'guardrails' => $result['guardrails']]);\n        }\n        return ['analysis_uuid' => $analysisUuid, 'status' => 'draft', 'row_version' => 1, 'result' => $result];",1)

# Decision record creation must be audit-atomic.
rep("        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $ok = $this->db->wpdb()->insert($this->db->table('decision_records'), [",
"        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_decision_transaction_failed','Decision transaction could not start.',['status'=>500]);}\n        $ok = $wpdb->insert($this->db->table('decision_records'), [",1)
rep("        if ($ok !== 1) {\n            return new WP_Error('smai_decision_store_failed', 'Decision record could not be stored.', ['status' => 500]);\n        }\n        $this->audit->log('decision_recorded', 'decision', $uuid, 'success', ['subject_type' => $subjectType, 'subject_ref' => $subjectRef], 'institutional_decision_support', null, $actorUserId);\n        return ['decision_uuid' => $uuid, 'status' => 'recorded', 'row_version' => 1];",
"        if ($ok !== 1) { $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_decision_store_failed', 'Decision record could not be stored.', ['status' => 500]);\n        }\n        if(!$this->audit->logInOpenTransaction('decision_recorded', 'decision', $uuid, 'success', ['subject_type' => $subjectType, 'subject_ref' => $subjectRef], 'institutional_decision_support', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_decision_audit_failed','Decision was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_decision_commit_failed','Decision could not be committed.',['status'=>500]);}\n        return ['decision_uuid' => $uuid, 'status' => 'recorded', 'row_version' => 1];",1)

# Decision outcome mutation must be audit-atomic.
rep("        $updated = $this->db->wpdb()->update($table, [\n            'outcome_json' => Json::canonical($outcome),",
"        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_decision_transaction_failed','Decision outcome transaction could not start.',['status'=>500]);}\n        $updated = $wpdb->update($table, [\n            'outcome_json' => Json::canonical($outcome),",1)
rep("        if ($updated !== 1) {\n            return new WP_Error('smai_decision_conflict', 'Decision record changed concurrently.', ['status' => 409]);\n        }\n        $this->audit->log('decision_outcome_recorded', 'decision', $decisionUuid, 'success', [], 'institutional_decision_support', null, $actorUserId);\n        return ['decision_uuid' => $decisionUuid, 'status' => 'outcome_recorded', 'row_version' => $expectedVersion + 1];",
"        if ($updated !== 1) { $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_decision_conflict', 'Decision record changed concurrently.', ['status' => 409]);\n        }\n        if(!$this->audit->logInOpenTransaction('decision_outcome_recorded', 'decision', $decisionUuid, 'success', [], 'institutional_decision_support', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_decision_audit_failed','Decision outcome was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_decision_commit_failed','Decision outcome could not be committed.',['status'=>500]);}\n        return ['decision_uuid' => $decisionUuid, 'status' => 'outcome_recorded', 'row_version' => $expectedVersion + 1];",1)

p.write_text(s,encoding='utf-8')

(root/'scripts/experiment-governance-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
s=(Path(__file__).resolve().parents[1]/'src/Domain/ExperimentService.php').read_text(encoding='utf-8')
e=[]
for action in ['experiment_created','experiment_transition','experiment_assignment_recorded','experiment_analysis_created','experiment_analysis_published']:
    if f\"audit->log('{action}'\" in s: e.append(f'{action} still uses nested/best-effort audit logging')
if \"audit->log('decision_recorded'\" in s or \"audit->log('decision_outcome_recorded'\" in s: e.append('decision mutation audit is not atomic')
commit_pos=s.find("if ($wpdb->query('COMMIT') === false)")
signal_pos=s.find("do_action('smai_experiment_guardrail_breached'")
if signal_pos != -1 and (commit_pos == -1 or signal_pos < commit_pos): e.append('guardrail signal can escape before durable analysis commit')
if e: print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Experiment governance invariants check passed.')
""",encoding='utf-8')
qa=root/'scripts/qa.sh'; q=qa.read_text(encoding='utf-8'); needle='python3 scripts/reporting-governance-invariants-check.py\n'
if 'experiment-governance-invariants-check.py' not in q:
    if needle not in q: raise SystemExit('qa insertion target missing')
    qa.write_text(q.replace(needle,needle+'python3 scripts/experiment-governance-invariants-check.py\n',1),encoding='utf-8')

(root/'docs/REVIEW-ROUND-16.md').write_text("""# Review Round 16 — Experiments, Analyses and Decision Records\n\nThe complete experiment/analysis/decision surface was audited before corrections began.\n\n## Confirmed defects\n1. Experiment create/transition/assignment/analysis/publish paths opened caller transactions but invoked `AuditLogger::log()`, creating nested transaction semantics instead of atomic audit append.\n2. Decision-record creation and outcome updates persisted state before best-effort audit logging; audit failure could leave an unaudited governance mutation.\n3. The guardrail-breach WordPress action was emitted before analysis persistence completed, so external listeners could observe a breach event even if the analysis transaction later failed.\n\n## Corrections\nAll experiment transaction-bound audit writes now use `logInOpenTransaction`; decision record/outcome changes commit atomically with audit evidence; guardrail breach signaling occurs only after durable analysis commit; a permanent QA invariant gate was added.\n\nNo staging/live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 16 corrections applied.')
