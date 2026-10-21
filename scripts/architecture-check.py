#!/usr/bin/env python3
from __future__ import annotations
import json,pathlib,re,sys
root=pathlib.Path(__file__).resolve().parents[1]
errors=[]
def need(path,needles):
 p=root/path
 if not p.is_file(): errors.append(f'missing:{path}');return
 text=p.read_text(encoding='utf-8')
 for n in needles:
  if n not in text: errors.append(f'{path}:missing:{n}')
required={
 'src/Domain/QueryPrivacyGuard.php':['differencingRisk','privacy_budget_exceeded'],
 'src/Domain/ExportControlService.php':['export_revoked'],
 'src/Domain/ReportControlService.php':['unsubscribe','report_revoked'],
 'src/Infrastructure/AuditVerifier.php':['record_hash_mismatch','head_hash_mismatch'],
 'src/Infrastructure/SchemaMigrator.php':['guardian_consent_version','experiment_subject','smai_schema_migration_error'],
 'src/Infrastructure/RuntimeGate.php':['SMAI_SCHEMA_VERSION','smai_schema_migration_error'],
 'src/Infrastructure/HealthService.php':['degradation_reasons','overdue_deletion_jobs','production_complete'],
 'src/Domain/FutureFeatureRegistry.php':['CF05-FUT-001','CF05-FUT-040','autonomous_decision'],
 'src/Domain/Future40Engine.php':['CF05-FUT-001','CF05-FUT-040','advisory_only'],
 'docs/FUTURE-40.md':['CF05-FUT-001','CF05-FUT-040','Status boundary'],
 'docs/THREE-PLAN-TRACEABILITY.md':['Definitive Integrated Master Plan','Recovered Directive Register','CF-05 Conditional Complete Master Plan'],
 'docs/REVIEW-ROUNDS-07-46.md':['REV-07','REV-46'],
}
for path,needles in required.items(): need(path,needles)
trace=(root/'docs/REQUIREMENTS-TRACEABILITY.md').read_text(encoding='utf-8') if (root/'docs/REQUIREMENTS-TRACEABILITY.md').is_file() else ''
for i in range(1,36):
 rid=f'CF05-FR-{i:03d}'
 if rid not in trace: errors.append(f'missing_requirement:{rid}')

# Freeze the governing 2026-09-16 CF-05 plan IDs, not just their existence.
# Otherwise a complete-looking ID table can silently associate requirements
# with the wrong architectural owner, implementation and acceptance test.
GOVERNING_PLAN_SUBJECTS = {
    1: "Event schema registry",
    2: "Minimum event envelope",
    3: "Purpose/consent gate",
    4: "Field redaction/tokenization",
    5: "Dedupe/order/late events",
    6: "Quarantine and feedback",
    7: "Canonical derivative modeling",
    8: "Slowly changing definitions",
    9: "Data lineage",
    10: "Backfill/rebuild",
    11: "Data quality rules",
    12: "Region/provider control",
    13: "Metric definition",
    14: "Metric lifecycle",
    15: "Dimension allowlist",
    16: "Freshness and confidence",
    17: "Attribution and funnels",
    18: "Financial/clinical metric limits",
    19: "Role/project-scoped dashboards",
    20: "Metric presentation",
    21: "Drilldown safety",
    22: "Scheduled reports",
    23: "Secure exports",
    24: "Narrative insights",
    25: "Experiment registry",
    26: "Assignment separation",
    27: "Guardrail monitoring",
    28: "Statistical integrity",
    29: "Decision record",
    30: "Retention tiers",
    31: "Deletion/anonymization propagation",
    32: "Access projects and expiry",
    33: "Query and export audit",
    34: "Provider/warehouse exit",
    35: "Restore and reproducibility",
}
for number, subject in GOVERNING_PLAN_SUBJECTS.items():
 rid=f'CF05-FR-{number:03d}'
 pattern=rf'^\| {rid} \| {re.escape(subject)} \|'
 if re.search(pattern, trace, re.M) is None:
  errors.append(f'governing_plan_subject_mismatch:{rid}:{subject}')

future=(root/'docs/FUTURE-40.md').read_text(encoding='utf-8') if (root/'docs/FUTURE-40.md').is_file() else ''
for i in range(1,41):
 fid=f'CF05-FUT-{i:03d}'
 if fid not in future: errors.append(f'missing_future_feature:{fid}')

reviews=(root/'docs/REVIEW-ROUNDS-07-46.md').read_text(encoding='utf-8') if (root/'docs/REVIEW-ROUNDS-07-46.md').is_file() else ''
for i in range(7,47):
 if f'REV-{i:02d}' not in reviews: errors.append(f'missing_review:REV-{i:02d}')
manifest=json.loads((root/'MANIFEST.json').read_text(encoding='utf-8'))
if (manifest.get('version'),manifest.get('schema_version'),manifest.get('contract_version')) != ('1.0.0-rc.17','1.4.1','1.4.0'):
 errors.append('release_identity_mismatch')
db=(root/'src/Infrastructure/Database.php').read_text(encoding='utf-8')
schema=(root/'src/Infrastructure/SchemaMigrator.php').read_text(encoding='utf-8')
tables=re.findall(r"'([a-z_]+)'", re.search(r'private const TABLES = \[(.*?)\];',db,re.S).group(1))
for table in tables:
 if f'CREATE TABLE {{$p}}{table} (' not in schema: errors.append(f'schema_missing_table:{table}')
if len(tables) < 45: errors.append(f'table_count_too_small:{len(tables)}')
if errors:
 print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print(f'Architecture check passed: 35 requirements, 40 review rounds, 40 Future-40 features, {len(tables)} governed tables.')
