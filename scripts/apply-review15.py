#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

def replace(path, old, new, count=1):
    p=root/path; s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'target missing: {path}: {old[:80]!r}')
    p.write_text(s.replace(old,new,count),encoding='utf-8')

# Existing caller transactions must use AuditLogger::logInOpenTransaction().
for file in ['src/Domain/AccessProjectService.php','src/Domain/DashboardService.php','src/Domain/ExportService.php','src/Domain/ExportControlService.php']:
    p=root/file; s=p.read_text(encoding='utf-8')
    # In these four services every audit call that occurs while an explicit transaction is open
    # is converted individually by action name below, leaving standalone read/access audit calls alone.
    actions={
      'src/Domain/AccessProjectService.php':['analytics_access_requested','analytics_access_granted','analytics_access_revoked','analytics_access_expired'],
      'src/Domain/DashboardService.php':['dashboard_registered','dashboard_activated'],
      'src/Domain/ExportService.php':['analytics_export_requested','analytics_export_ready'],
      'src/Domain/ExportControlService.php':['analytics_export_revoked'],
    }[file]
    for action in actions:
        needle=f"$this->audit->log('{action}'"
        if needle in s: s=s.replace(needle,f"$this->audit->logInOpenTransaction('{action}'")
        else:
            needle2=f"$this->audit->log(\n            '{action}'"
            if needle2 in s: s=s.replace(needle2,f"$this->audit->logInOpenTransaction(\n            '{action}'")
            else: raise SystemExit(f'audit target missing {file} {action}')
    p.write_text(s,encoding='utf-8')

# Export control defense-in-depth: read-only auditor must never authorize a mutation.
replace('src/Domain/ExportControlService.php',
"if ((int) $export['requester_user_id'] !== $actorUserId && !user_can($actorUserId, 'smai_manage_access') && !user_can($actorUserId, 'smai_audit'))",
"if ((int) $export['requester_user_id'] !== $actorUserId && !user_can($actorUserId, 'smai_manage_access'))")

# ReportService: report creation and activation must be audit-atomic.
p=root/'src/Domain/ReportService.php'; s=p.read_text(encoding='utf-8')
s=s.replace("        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $ok = $this->db->wpdb()->insert($this->db->table('reports'), [",
"        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_report_transaction_failed', 'Report transaction could not start.', ['status'=>500]);\n        }\n        $ok = $wpdb->insert($this->db->table('reports'), [",1)
s=s.replace("        if ($ok !== 1) {\n            return new WP_Error('smai_report_store_failed', 'Report could not be stored.', ['status' => 500]);\n        }\n        $this->audit->log('report_created', 'report', $uuid, 'success', ['project_uuid' => $projectUuid], 'institutional_reporting', null, $actorUserId);\n        return ['report_uuid' => $uuid, 'state' => 'draft', 'row_version' => 1];",
"        if ($ok !== 1) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_report_store_failed', 'Report could not be stored.', ['status' => 500]);\n        }\n        if (!$this->audit->logInOpenTransaction('report_created', 'report', $uuid, 'success', ['project_uuid' => $projectUuid], 'institutional_reporting', null, $actorUserId)) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_report_audit_failed', 'Report was not committed because audit evidence failed.', ['status'=>503]);\n        }\n        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_commit_failed','Report could not be committed.',['status'=>500]); }\n        return ['report_uuid' => $uuid, 'state' => 'draft', 'row_version' => 1];",1)
# activation: transaction includes state, optional job and audit.
s=s.replace("        $schedule = $report['schedule_rrule'] === null ? null : (string) $report['schedule_rrule'];\n        $next = $schedule === null ? null : gmdate('Y-m-d H:i:s', $this->nextTimestamp($schedule, time()));\n        $updated = $this->db->wpdb()->update($table, [",
"        $schedule = $report['schedule_rrule'] === null ? null : (string) $report['schedule_rrule'];\n        $next = $schedule === null ? null : gmdate('Y-m-d H:i:s', $this->nextTimestamp($schedule, time()));\n        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_report_transaction_failed','Report activation transaction could not start.',['status'=>500]);}\n        $updated = $wpdb->update($table, [",1)
s=s.replace("        if ($updated !== 1) {\n            return new WP_Error('smai_report_conflict', 'Report changed concurrently.', ['status' => 409]);\n        }",
"        if ($updated !== 1) { $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_report_conflict', 'Report changed concurrently.', ['status' => 409]);\n        }",1)
s=s.replace("            if (is_wp_error($job)) {\n                $this->db->wpdb()->update($table, ['state' => 'paused', 'updated_at' => $this->db->now()], ['id' => (int) $report['id'], 'state' => 'active']);\n                return $job;\n            }\n        }\n        $this->audit->log('report_activated', 'report', $uuid, 'success', ['schedule' => $schedule], 'institutional_reporting', null, $actorUserId);\n        return ['report_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1, 'next_run_at' => $next];",
"            if (is_wp_error($job)) { $wpdb->query('ROLLBACK'); return $job; }\n        }\n        if(!$this->audit->logInOpenTransaction('report_activated', 'report', $uuid, 'success', ['schedule' => $schedule], 'institutional_reporting', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_audit_failed','Report activation was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_report_commit_failed','Report activation could not be committed.',['status'=>500]);}\n        return ['report_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1, 'next_run_at' => $next];",1)
# Report delivery identities must be keyed, not dictionary-hashable.
s=s.replace("            $runKey = hash('sha256', $uuid . '|' . $runRef . '|user:' . $userId);","            $runKey = hash_hmac('sha256', $uuid . '|' . $runRef . '|user:' . $userId, $this->privacyKey());",1)
s=s.replace("                'recipient_hash' => hash('sha256', 'user:' . $userId),","                'recipient_hash' => $this->recipientHash($userId),",1)
s=s.replace("            || !hash_equals((string) $delivery['recipient_hash'], hash('sha256', 'user:' . $actorUserId))","            || !hash_equals((string) $delivery['recipient_hash'], $this->recipientHash($actorUserId))",1)
# Fail closed on delivery access audit and state recording.
s=s.replace("        $this->db->wpdb()->update($table, ['state' => 'sent', 'sent_at' => $delivery['sent_at'] ?? $this->db->now(), 'updated_at' => $this->db->now()], ['id' => (int) $delivery['id']]);\n        return Json::object((string) $delivery['bundle_json']);",
"        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_report_delivery_unavailable','Report delivery audit is unavailable.',['status'=>503]);}\n        $marked=$wpdb->update($table, ['state' => 'sent', 'sent_at' => $delivery['sent_at'] ?? $this->db->now(), 'updated_at' => $this->db->now()], ['id' => (int) $delivery['id']]);\n        if($marked===false || !$this->audit->logInOpenTransaction('report_delivery_accessed','report_delivery',$deliveryUuid,'success',['report_uuid'=>(string)$delivery['report_uuid']],'institutional_reporting',null,$actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_delivery_unavailable','Report delivery audit could not be committed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_report_delivery_unavailable','Report delivery could not be committed.',['status'=>503]);}\n        return Json::object((string) $delivery['bundle_json']);",1)
# Add key helpers.
s=s.replace("    private function nextTimestamp(string $schedule, int $from): int\n    {",
"    private function privacyKey(): string\n    {\n        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {\n            throw new \\RuntimeException('Report pseudonym key is unavailable.');\n        }\n        return SMAI_PSEUDONYM_KEY;\n    }\n\n    private function recipientHash(int $userId): string\n    {\n        return hash_hmac('sha256', 'user:' . $userId, $this->privacyKey());\n    }\n\n    private function nextTimestamp(string $schedule, int $from): int\n    {",1)
p.write_text(s,encoding='utf-8')

# ReportControlService: keyed recipient refs and fail-closed delivery revocation.
p=root/'src/Domain/ReportControlService.php'; s=p.read_text(encoding='utf-8')
s=s.replace("$this->revokeDeliveries($uuid, hash('sha256', 'user:' . $actorUserId), $now);","if (!$this->revokeDeliveries($uuid, $this->recipientHash($actorUserId), $now)) { return new WP_Error('smai_report_delivery_revoke_failed','Report delivery revocation failed.',['status'=>503]); }",1)
s=s.replace("        $this->revokeDeliveries($uuid, null, $now);","        if (!$this->revokeDeliveries($uuid, null, $now)) { return new WP_Error('smai_report_delivery_revoke_failed','Report delivery revocation failed.',['status'=>503]); }",1)
s=s.replace("    private function revokeDeliveries(string $uuid, ?string $recipientHash, string $now): void","    private function revokeDeliveries(string $uuid, ?string $recipientHash, string $now): bool",1)
s=s.replace("        $this->db->wpdb()->query($this->db->wpdb()->prepare(\n            \"UPDATE `{$this->db->table('report_deliveries')}` SET state='revoked',revoked_at=%s,expires_at=%s,token_hash=NULL,updated_at=%s WHERE {$where}\",\n            ...$args\n        ));\n    }",
"        return $this->db->wpdb()->query($this->db->wpdb()->prepare(\n            \"UPDATE `{$this->db->table('report_deliveries')}` SET state='revoked',revoked_at=%s,expires_at=%s,token_hash=NULL,updated_at=%s WHERE {$where}\",\n            ...$args\n        )) !== false;\n    }",1)
s=s.replace("    private function get(string $uuid): ?array\n    {",
"    private function recipientHash(int $userId): string\n    {\n        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) { throw new \\RuntimeException('Report pseudonym key is unavailable.'); }\n        return hash_hmac('sha256', 'user:' . $userId, SMAI_PSEUDONYM_KEY);\n    }\n\n    private function get(string $uuid): ?array\n    {",1)
p.write_text(s,encoding='utf-8')

# Narrative create/publish: mutation and audit commit together.
p=root/'src/Domain/NarrativeService.php'; s=p.read_text(encoding='utf-8')
s=s.replace("        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $ok = $this->db->wpdb()->insert($this->db->table('narratives'), [",
"        $uuid = Uuid::v4();\n        $now = $this->db->now();\n        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_narrative_transaction_failed','Narrative transaction could not start.',['status'=>500]);}\n        $ok = $wpdb->insert($this->db->table('narratives'), [",1)
s=s.replace("        if ($ok !== 1) {\n            return new WP_Error(\n                'smai_narrative_store_failed',",
"        if ($ok !== 1) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error(\n                'smai_narrative_store_failed',",1)
s=s.replace("        $this->audit->log(\n            'narrative_insight_created',","        if(!$this->audit->logInOpenTransaction(\n            'narrative_insight_created',",1)
s=s.replace("            $actorUserId\n        );\n\n        return [\n            'insight_uuid' => $uuid,",
"            $actorUserId\n        )) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_narrative_audit_failed','Narrative was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_narrative_commit_failed','Narrative could not be committed.',['status'=>500]);}\n\n        return [\n            'insight_uuid' => $uuid,",1)
# publish transaction
s=s.replace("        $updated = $this->db->wpdb()->update($table, [\n            'state' => 'published',",
"        $wpdb=$this->db->wpdb();\n        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_narrative_transaction_failed','Narrative publication transaction could not start.',['status'=>500]);}\n        $updated = $wpdb->update($table, [\n            'state' => 'published',",1)
s=s.replace("        if ($updated !== 1) {\n            return new WP_Error('smai_narrative_conflict', 'Narrative changed concurrently.', ['status' => 409]);\n        }\n\n        $this->audit->log(\n            'narrative_insight_published',",
"        if ($updated !== 1) { $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_narrative_conflict', 'Narrative changed concurrently.', ['status' => 409]);\n        }\n\n        if(!$this->audit->logInOpenTransaction(\n            'narrative_insight_published',",1)
s=s.replace("            $reviewerUserId\n        );\n\n        return [\n            'insight_uuid' => $uuid,\n            'state' => 'published',",
"            $reviewerUserId\n        )) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_narrative_audit_failed','Narrative publication was not committed because audit evidence failed.',['status'=>503]); }\n        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_narrative_commit_failed','Narrative publication could not be committed.',['status'=>500]);}\n\n        return [\n            'insight_uuid' => $uuid,\n            'state' => 'published',",1)
p.write_text(s,encoding='utf-8')

# Permanent review-15 invariant gate.
(root/'scripts/reporting-governance-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
files={n:(r/'src/Domain'/n).read_text(encoding='utf-8') for n in ['AccessProjectService.php','DashboardService.php','ExportService.php','ExportControlService.php','ReportService.php','ReportControlService.php','NarrativeService.php']}
e=[]
for n in ['AccessProjectService.php','DashboardService.php','ExportService.php','ExportControlService.php']:
    if "START TRANSACTION" in files[n] and "audit->log('analytics_" in files[n]:
        e.append(f'{n}: nested/best-effort analytics audit remains in explicit transaction')
if "smai_audit'))" in files['ExportControlService.php']: e.append('read-only auditor still authorizes export mutation internally')
if "hash('sha256', 'user:'" in files['ReportService.php'] or "hash('sha256', 'user:'" in files['ReportControlService.php']: e.append('report recipient identity uses unkeyed hash')
if 'report_delivery_accessed' not in files['ReportService.php']: e.append('report delivery access is not auditable fail-closed')
if 'logInOpenTransaction' not in files['NarrativeService.php']: e.append('narrative mutations are not audit-atomic')
if e: print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Reporting governance invariants check passed.')
""",encoding='utf-8')
p=root/'scripts/qa.sh'; s=p.read_text(encoding='utf-8'); needle='python3 scripts/pipeline-quality-invariants-check.py\n'
if 'reporting-governance-invariants-check.py' not in s:
    if needle not in s: raise SystemExit('qa insertion missing')
    p.write_text(s.replace(needle,needle+'python3 scripts/reporting-governance-invariants-check.py\n',1),encoding='utf-8')

(root/'docs/REVIEW-ROUND-15.md').write_text("""# Review Round 15 — Access, Dashboards, Reports, Exports and Narratives\n\nThe full access/reporting surface was reviewed before this defect ledger was frozen and before corrections began.\n\n## Confirmed defects\n1. Access-project, dashboard and export code opened database transactions and then called `AuditLogger::log()`, which starts another transaction instead of appending to the caller transaction. The intended domain-write/audit atomicity was therefore not reliable.\n2. Report creation/activation/control and narrative create/publish contained best-effort or ignored audit writes after state mutation; some report-dependent delivery revocations also ignored database failure.\n3. Report delivery recipient references used plain SHA-256 over low-cardinality user IDs, enabling dictionary recovery of stored pseudonyms.\n4. Report-delivery access changed delivery state and returned the bundle without requiring durable access-audit evidence.\n5. ExportControlService still admitted the read-only `smai_audit` capability as a mutation authority internally even though the REST route had already been corrected.\n\n## Corrections\nExplicit caller transactions use `logInOpenTransaction`; core report/narrative mutations fail closed on audit failure; delivery recipient references/run keys are HMAC-keyed; delivery access requires durable audit evidence; delivery-revocation failures are surfaced; read-only auditor mutation authority was removed; permanent QA invariants were added.\n\nNo staging or live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 15 corrections applied.')
