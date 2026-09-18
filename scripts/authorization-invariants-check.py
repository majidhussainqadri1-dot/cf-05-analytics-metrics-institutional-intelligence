#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
future=(root/'src/Domain/FutureFeatureService.php').read_text(encoding='utf-8')
gov=(root/'src/Http/GovernanceRestController.php').read_text(encoding='utf-8')
access=(root/'src/Domain/AccessProjectService.php').read_text(encoding='utf-8')
dash=(root/'src/Domain/DashboardService.php').read_text(encoding='utf-8')
rest=(root/'src/Http/RestController.php').read_text(encoding='utf-8')
event=(root/'src/Domain/EventSchemaRegistry.php').read_text(encoding='utf-8')
dataset=(root/'src/Domain/DatasetCatalog.php').read_text(encoding='utf-8')
metric=(root/'src/Domain/MetricCatalog.php').read_text(encoding='utf-8')
lifecycle=(root/'src/Domain/CatalogLifecycleService.php').read_text(encoding='utf-8')
quality=(root/'src/Domain/QualityService.php').read_text(encoding='utf-8')
backfill=(root/'src/Domain/BackfillService.php').read_text(encoding='utf-8')
snapshot=(root/'src/Domain/SnapshotService.php').read_text(encoding='utf-8')
query=(root/'src/Domain/MetricQueryService.php').read_text(encoding='utf-8')
export=(root/'src/Domain/ExportService.php').read_text(encoding='utf-8')
export_control=(root/'src/Domain/ExportControlService.php').read_text(encoding='utf-8')
provider=(root/'src/Domain/ProviderService.php').read_text(encoding='utf-8')
deletion=(root/'src/Domain/DeletionService.php').read_text(encoding='utf-8')
restore=(root/'src/Domain/RestoreService.php').read_text(encoding='utf-8')
errors=[]
if "if(!$dryRun&&!FutureActivationService::isApproved())" not in future:
    errors.append('active Future-40 execution does not re-check global Future-40 approval')
revoke=gov.split("'/exports/(?P<uuid>[0-9a-fA-F-]{36})/revoke'",1)[-1].split(']);',1)[0]
if "smai_audit" in revoke:
    errors.append('read-only auditor can mutate export revocation')
if access.count("user_can($actorUserId, 'smai_manage_access')") < 3:
    errors.append('access project mutation service-layer authorization incomplete')
if "user_can($actorUserId, 'smai_manage_reports')" not in dash:
    errors.append('dashboard registration lacks service-layer manage_reports authorization')
if "user_can($actorUserId, 'smai_approve_catalog')" not in dash:
    errors.append('dashboard activation lacks service-layer approval authorization')
if "user_can($actorUserId, 'smai_view_insights')" not in dash or "user_can($actorUserId, 'smai_audit')" not in dash:
    errors.append('dashboard read service-layer capability boundary incomplete')
if "current_user_can('smai_view_insights') || current_user_can('smai_audit')" not in rest:
    errors.append('dashboard REST audience capability contract differs from service audience')
for body,name in [(event,'event schema'),(dataset,'dataset'),(metric,'metric')]:
    if "user_can($actorUserId, 'smai_manage_catalog')" not in body: errors.append(name+' registration lacks service-layer manage_catalog authorization')
if "user_can($actorUserId, 'smai_approve_catalog')" not in lifecycle: errors.append('catalog lifecycle lacks service-layer approval authorization')
if quality.count("user_can($actorUserId, 'smai_manage_quality')") < 2 or "user_can($actorUserId, 'smai_approve_catalog')" not in quality: errors.append('quality service authorization incomplete')
if backfill.count("user_can($actorUserId, 'smai_manage_backfills')") < 2 or backfill.count("user_can($actorUserId, 'smai_approve_catalog')") < 2 or "user_can($actorUserId, 'smai_restore')" not in backfill: errors.append('backfill service authorization incomplete')
if "user_can($actorUserId, 'smai_manage_quality')" not in snapshot: errors.append('snapshot enqueue lacks service-layer quality authorization')
if "user_can($actorUserId, 'smai_query_metrics')" not in query: errors.append('metric query lacks service-layer query authorization')
if export.count("user_can($actorUserId, 'smai_export_metrics')") < 2: errors.append('export request/download service authorization incomplete')
if "user_can($actorUserId, 'smai_export_metrics')" not in export_control or "user_can($actorUserId, 'smai_manage_access')" not in export_control: errors.append('export revocation service authorization incomplete')
if provider.count("user_can($actorUserId,'smai_manage_providers')") < 2: errors.append('provider service authorization incomplete')
if "user_can($actorUserId, 'smai_manage_deletions')" not in deletion: errors.append('deletion request lacks service-layer authorization')
if restore.count("user_can($actorUserId, 'smai_restore')") + restore.count("user_can($actorUserId,'smai_restore')") < 2: errors.append('restore service authorization incomplete')
if errors:
    print('\n'.join(errors), file=sys.stderr); sys.exit(1)
print('Authorization invariants check passed.')
