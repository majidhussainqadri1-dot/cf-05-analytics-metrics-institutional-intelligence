#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1];errors=[]
def t(p):return (root/p).read_text(encoding='utf-8')
for p,n in [('src/Domain/AccessProjectService.php','strictTimestamp'),('src/Domain/DashboardService.php','storedWidgetsValidForActivation'),('src/Domain/NarrativeService.php','citationsAvailable'),('src/Domain/ExperimentService.php','metricsAvailableForExperiment'),('src/Infrastructure/HealthService.php','audit_chain_unverified'),('src/Infrastructure/RepairService.php','smai_future_intelligence_tick'),('src/Domain/FutureArtifactStore.php','smai_future_research_dataset_unavailable'),('src/Http/FutureRestController.php','smai_future_invalid_dry_run'),('src/Infrastructure/SchemaMigrator.php','KEY created_at (created_at)')]:
    if n not in t(p):errors.append(f'{p}:missing:{n}')
plugin=t('sabri-analytics-institutional-intelligence.php')
for n in ["SMAI_VERSION', '1.0.0-rc.7'","SMAI_SCHEMA_VERSION', '1.4.1'","SMAI_CONTRACT_VERSION', '1.4.0'"]:
    if n not in plugin:errors.append('release_identity:'+n)
for name in ['dataset-definition.schema.json','event-envelope.schema.json','experiment-definition.schema.json','metric-response.schema.json','module-manifest.schema.json']:
    if '1.4.0' not in t('contracts/'+name):errors.append('contract_id_stale:'+name)
if errors:print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Sequential review 30-39 invariants check passed.')
