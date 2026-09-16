#!/usr/bin/env python3
from __future__ import annotations
import pathlib,sys,json
root=pathlib.Path(__file__).resolve().parents[1]
errors=[]

def text(path:str)->str:
    p=root/path
    if not p.is_file(): errors.append(f'missing:{path}'); return ''
    return p.read_text(encoding='utf-8')

registry=text('src/Domain/FutureFeatureRegistry.php')
engine=text('src/Domain/Future40Engine.php')
service=text('src/Domain/FutureFeatureService.php')
controller=text('src/Http/FutureRestController.php')
schema=text('src/Infrastructure/SchemaMigrator.php')
db=text('src/Infrastructure/Database.php')
qa=text('scripts/qa.sh')
plan=text('docs/FUTURE-40.md')
manifest=json.loads(text('MANIFEST.json') or '{}')
ids=[f'CF05-FUT-{i:03d}' for i in range(1,41)]
for fid in ids:
    if fid not in registry: errors.append(f'registry_missing:{fid}')
    if fid not in engine: errors.append(f'engine_missing:{fid}')
    if fid not in plan: errors.append(f'plan_missing:{fid}')
for token in ['advisory_only','aggregate_only','human_governed','autonomous_decision']:
    if token not in registry and token not in engine: errors.append(f'safety_marker_missing:{token}')
for token in ['future_features','future_runs','analytics_incidents','scenario_models','research_workspaces','intelligence_alerts','transparency_records','privacy_budgets']:
    if f"'{token}'" not in db: errors.append(f'database_whitelist_missing:{token}')
    if f'CREATE TABLE {{$p}}{token} (' not in schema: errors.append(f'schema_table_missing:{token}')
for token in ['/future/features','/configure','/transition','/run','/future/incidents']:
    if token not in controller: errors.append(f'route_missing:{token}')
for token in ['smai_future40_approved','SMAI_FUTURE40_EVIDENCE_SHA256','Independent approval','RuntimeGate::queryEnabled']:
    if token not in service: errors.append(f'activation_guard_missing:{token}')
for token in ['tests/future40.php','scripts/future40-check.py']:
    if token not in qa: errors.append(f'qa_gate_missing:{token}')
if manifest.get('version')!='1.0.0-rc.6': errors.append('manifest_version_not_rc6')
if manifest.get('schema_version')!='1.4.0': errors.append('manifest_schema_not_1_4_0')
if manifest.get('contract_version')!='1.4.0': errors.append('manifest_contract_not_1_4_0')
if 'contracts/future-feature.schema.json' not in manifest.get('public_contracts',[]): errors.append('future_contract_not_manifested')
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Future-40 check passed: 40 features, governed routes, persistence, activation gates and QA coverage.')
