#!/usr/bin/env python3
from pathlib import Path
import sys

root=Path(__file__).resolve().parents[1]
errors=[]

def text(path: str) -> str:
    return (root/path).read_text(encoding='utf-8')

def compact(value: str) -> str:
    return ''.join(value.split())

checks=[
    ('src/Infrastructure/AuditVerifier.php', "START TRANSACTION WITH CONSISTENT SNAPSHOT"),
    ('src/Infrastructure/AuditVerifier.php', "Json::canonical(["),
    ('src/Infrastructure/AuditVerifier.php', "audit_state_row_version_mismatch"),
    ('src/Admin/AdminPages.php', "$this->health->report(false)"),
    ('src/Infrastructure/RepairService.php', "analytics_safe_repair_completed"),
    ('src/Domain/DeletionService.php', "$completionActorType"),
    ('src/Domain/QualityService.php', "Quality-run transaction could not start."),
    ('src/Domain/QualityService.php', "categories_evaluated"),
    ('src/Domain/SnapshotService.php', "FOR UPDATE"),
    ('src/Domain/SnapshotService.php', "(string) $previous['state'] === 'published'"),
    ('src/Domain/DashboardService.php', "SnapshotDisclosurePolicy::evaluate"),
    ('src/Domain/ReportService.php', "SnapshotDisclosurePolicy::evaluate"),
    ('src/Domain/ExportService.php', "SnapshotDisclosurePolicy::evaluate"),
    ('src/Domain/ExperimentService.php', "deletion_key FROM"),
    ('src/Domain/ExperimentService.php', "$target === 'reviewed'"),
    ('src/Domain/ExperimentService.php', "decisionSubjectExists"),
    ('src/Infrastructure/IdempotencyGuard.php', "validIdentity"),
    ('src/Infrastructure/IdempotencyGuard.php', "Expired idempotency evidence could not be recycled safely."),
    ('src/Domain/EventSchemaRegistry.php', "RuntimeGate::catalogEnabled()"),
    ('src/Domain/DatasetCatalog.php', "RuntimeGate::catalogEnabled()"),
    ('src/Domain/MetricCatalog.php', "RuntimeGate::catalogEnabled()"),
    ('src/Domain/CatalogLifecycleService.php', "RuntimeGate::catalogEnabled()"),
    ('src/Domain/QualityService.php', "RuntimeGate::catalogEnabled()"),
]
for path,needle in checks:
    if compact(needle) not in compact(text(path)):
        errors.append(f'{path}:missing:{needle}')

plugin=text('sabri-analytics-institutional-intelligence.php')
for needle in ["SMAI_VERSION', '1.0.0-rc.9'","SMAI_SCHEMA_VERSION', '1.4.1'","SMAI_CONTRACT_VERSION', '1.4.0'"]:
    if needle not in plugin:
        errors.append('release_identity:'+needle)

manifest=text('MANIFEST.json')
for needle in ['"version": "1.0.0-rc.9"','50 fresh review/fix rounds','v1.1 Future40 Amended']:
    if needle not in manifest:
        errors.append('manifest:'+needle)

qa=text('scripts/qa.sh')
if 'python3 scripts/review50-59-invariants-check.py' not in qa:
    errors.append('qa:review50-59-invariants-check.py')

if errors:
    print('Sequential review 50-59 invariant regression:\n'+'\n'.join(errors),file=sys.stderr)
    sys.exit(1)
print('Sequential review 50-59 invariants check passed.')
