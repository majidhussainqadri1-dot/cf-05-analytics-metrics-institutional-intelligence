#!/usr/bin/env python3
from __future__ import annotations
import json, pathlib, re, sys
root = pathlib.Path(__file__).resolve().parents[1]
errors: list[str] = []
required = [
    'src/Infrastructure/SchemaMigrator.php','src/Infrastructure/RuntimeGate.php','src/Infrastructure/RuntimeActivationService.php','src/Infrastructure/JobQueue.php',
    'src/Domain/EventIngestionService.php','src/Domain/DatasetCatalog.php','src/Domain/PipelineService.php',
    'src/Domain/BackfillService.php','src/Domain/QualityService.php','src/Domain/SnapshotService.php',
    'src/Domain/AccessProjectService.php','src/Domain/ExportService.php','src/Domain/ExportControlService.php','src/Domain/ReportService.php','src/Domain/ReportControlService.php',
    'src/Domain/ExperimentService.php','src/Domain/DeletionService.php','src/Domain/ProviderService.php',
    'src/Domain/RestoreService.php','src/Domain/PrivacyQueryPolicy.php','src/Domain/QueryPrivacyGuard.php',
    'src/Http/RestController.php','src/Http/GovernanceRestController.php','src/Admin/AdminPages.php',
    'docs/REQUIREMENTS-TRACEABILITY.md','docs/THREE-PLAN-TRACEABILITY.md','MANIFEST.json'
]
for item in required:
    if not (root/item).is_file(): errors.append(f'missing required file: {item}')

manifest = json.loads((root/'MANIFEST.json').read_text())
bootstrap = (root/'sabri-analytics-institutional-intelligence.php').read_text()
for constant, key in [('SMAI_VERSION','version'),('SMAI_SCHEMA_VERSION','schema_version'),('SMAI_CONTRACT_VERSION','contract_version')]:
    m = re.search(rf"define\('{constant}', '([^']+)'\)", bootstrap)
    if not m or m.group(1) != manifest[key]: errors.append(f'{constant} does not match manifest')

schema = (root/'src/Infrastructure/SchemaMigrator.php').read_text()
tables = set(re.findall(r'CREATE TABLE \{\$p\}([a-z_]+)', schema))
db = (root/'src/Infrastructure/Database.php').read_text()
match = re.search(r'private const TABLES = \[(.*?)\];', db, re.S)
allowed = set(re.findall(r"'([a-z_]+)'", match.group(1) if match else ''))
if tables != allowed:
    errors.append('schema/database table registry mismatch: ' + repr(sorted(tables ^ allowed)))

trace = (root/'docs/REQUIREMENTS-TRACEABILITY.md').read_text() if (root/'docs/REQUIREMENTS-TRACEABILITY.md').exists() else ''
for i in range(1,36):
    rid=f'CF05-FR-{i:03d}'
    if rid not in trace: errors.append(f'missing traceability ID: {rid}')

all_php = '\n'.join(p.read_text(errors='replace') for p in root.rglob('*.php'))
for token in ['eval(', 'create_function(', 'shell_exec(', 'passthru(', 'proc_open(']:
    if token in all_php: errors.append(f'prohibited runtime primitive: {token}')
if 'SMAI_ACTIVATION_EVIDENCE_SHA256' not in all_php or 'foundation_disabled' not in all_php:
    errors.append('evidence-bound disabled-by-default runtime gate missing')

for required_token in [
    'snapshot_revision int unsigned', 'supersedes_snapshot_id bigint unsigned',
    'previous_build_uuid char(36)', 'recorded_by bigint unsigned',
    "'/backfills/(?P<uuid>[0-9a-fA-F-]{36})/rollback'",
    "'/runtime/activation/propose'", "'/quality/rules/(?P<rule>",
    'QueryPrivacyGuard', 'smai_differencing_risk', 'dimension_policies',
    "'/exports/(?P<uuid>[0-9a-fA-F-]{36})/revoke'",
    "'/reports/(?P<uuid>[0-9a-fA-F-]{36})/unsubscribe'",
    'smai_analytics_approver', 'wp_unique_id'
]:
    if required_token not in (schema + all_php): errors.append(f'missing completion control: {required_token}')
if re.search(r'\b(?:TODO|FIXME|not implemented)\b', all_php, re.I):
    errors.append('unfinished source marker found')

if errors:
    for error in errors: print('ERROR:', error, file=sys.stderr)
    sys.exit(1)
print(f'Architecture check passed: {len(required)} required files, {len(tables)} governed tables, 35 requirement IDs and three-plan controls.')
