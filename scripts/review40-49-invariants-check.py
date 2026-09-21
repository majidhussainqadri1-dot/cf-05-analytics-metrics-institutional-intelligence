#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1];errors=[]
def t(p): return (root/p).read_text(encoding='utf-8')
checks=[('src/Domain/EventIngestionService.php','checkdate('),('src/Infrastructure/JobQueue.php','lease_until>='),('src/Domain/QueryPrivacyGuard.php','slice_fingerprint'),('src/Domain/ExportService.php','smai_export_commit_failed'),('src/Domain/ReportService.php','definitionStillGoverned'),('src/Domain/ReportControlService.php','logInOpenTransaction'),('src/Domain/DeletionService.php','Deletion export payload purge failed.'),('src/Domain/ProviderService.php','revocationVerified'),('src/Domain/RestoreService.php','smai_restore_already_verified'),('src/Http/RestController.php','dashboardBundle')]
for p,n in checks:
    if n not in t(p): errors.append(f'{p}:missing:{n}')
plugin=t('sabri-analytics-institutional-intelligence.php')
for n in ["SMAI_VERSION', '1.0.0-rc.10'","SMAI_SCHEMA_VERSION', '1.4.1'","SMAI_CONTRACT_VERSION', '1.4.0'"]:
    if n not in plugin: errors.append('release_identity:'+n)
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Sequential review 40-49 invariants check passed.')
