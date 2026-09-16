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
 'docs/THREE-PLAN-TRACEABILITY.md':['Definitive Integrated Master Plan','Recovered Directive Register','CF-05 Conditional Complete Master Plan'],
 'docs/REVIEW-ROUNDS-07-46.md':['REV-07','REV-46'],
}
for path,needles in required.items(): need(path,needles)
trace=(root/'docs/REQUIREMENTS-TRACEABILITY.md').read_text(encoding='utf-8') if (root/'docs/REQUIREMENTS-TRACEABILITY.md').is_file() else ''
for i in range(1,36):
 rid=f'CF05-FR-{i:03d}'
 if rid not in trace: errors.append(f'missing_requirement:{rid}')
reviews=(root/'docs/REVIEW-ROUNDS-07-46.md').read_text(encoding='utf-8') if (root/'docs/REVIEW-ROUNDS-07-46.md').is_file() else ''
for i in range(7,47):
 if f'REV-{i:02d}' not in reviews: errors.append(f'missing_review:REV-{i:02d}')
manifest=json.loads((root/'MANIFEST.json').read_text(encoding='utf-8'))
if (manifest.get('version'),manifest.get('schema_version'),manifest.get('contract_version')) != ('1.0.0-rc.4','1.2.0','1.3.0'):
 errors.append('release_identity_mismatch')
db=(root/'src/Infrastructure/Database.php').read_text(encoding='utf-8')
schema=(root/'src/Infrastructure/SchemaMigrator.php').read_text(encoding='utf-8')
tables=re.findall(r"'([a-z_]+)'", re.search(r'private const TABLES = \[(.*?)\];',db,re.S).group(1))
for table in tables:
 if f'CREATE TABLE {{$p}}{table} (' not in schema: errors.append(f'schema_missing_table:{table}')
if len(tables) < 37: errors.append(f'table_count_too_small:{len(tables)}')
if errors:
 print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print(f'Architecture check passed: 35 requirements, 40 review rounds, {len(tables)} governed tables.')
