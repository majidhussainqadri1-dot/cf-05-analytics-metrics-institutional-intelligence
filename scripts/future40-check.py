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
activation=text('src/Infrastructure/FutureActivationService.php')
schema=text('src/Infrastructure/SchemaMigrator.php')
db=text('src/Infrastructure/Database.php')
audit=text('src/Infrastructure/AuditLogger.php')
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
for token in ['/future/features','/configure','/transition','/run','/future/incidents','/future/runtime/activation/propose','/future/runtime/activation/approve','/future/runtime/disable']:
    if token not in controller: errors.append(f'route_missing:{token}')
for token in ['tests/future40.php','scripts/future40-check.py']:
    if token not in qa: errors.append(f'qa_gate_missing:{token}')
# Review-1 security/lifecycle invariants.
if "current_user_can($required)||current_user_can('smai_run_future_intelligence')" in controller.replace(' ', ''):
    errors.append('feature_specific_capability_bypass_present')
if "return $required!=='' && current_user_can($required);" not in controller:
    errors.append('feature_specific_capability_enforcement_missing')
for token in ["'state'=>'configured'","smai_future_retired","smai_future_approval_integrity","smai_future_schema_gate","including dry-run"]:
    if token not in service: errors.append(f'review1_guard_missing:{token}')
# Review-2 persistence/audit invariants.
for token in ['logInOpenTransaction','private function append','FOR UPDATE']:
    if token not in audit: errors.append(f'audit_atomicity_missing:{token}')
for token in ['smai_future_run_store_failed','smai_future_incident_store_failed','analytics_incident_created','future_scheduled_evidence_created','rollbackError','START TRANSACTION']:
    if token not in service: errors.append(f'review2_persistence_guard_missing:{token}')
if service.count('logInOpenTransaction') < 5:
    errors.append('future40_mutations_not_fully_audit_bound')
# Review-3 global activation invariants.
for token in ['smai_future40_activation_request','smai_future40_state','future40_activation_proposed','future40_activation_approved','future40_activation_disabled','independent approver','failClosed','isApproved']:
    if token not in activation: errors.append(f'review3_activation_guard_missing:{token}')
if "get_option('smai_future40_state', 'disabled') !== 'approved'" not in activation and "get_option(self::OPTION_STATE, 'disabled') !== 'approved'" not in activation:
    errors.append('future40_state_not_enforced')
if 'FutureActivationService::isApproved()' not in service:
    errors.append('feature_service_not_bound_to_global_activation_gate')
# Review-4 semantic invariants.
for token in ['breaking_added_required_fields','zero_variance_baseline','over_budget','requires_review','metric_not_allowlisted','human_review_confirmed','is_finite','validDate']:
    if token not in engine: errors.append(f'review4_semantic_guard_missing:{token}')
# Review-7 privacy/retention invariants.
retention=text('src/Infrastructure/RetentionRunner.php')
activator=text('src/Infrastructure/Activator.php')
artifact=text('src/Domain/FutureArtifactStore.php')
for token in ['smai_future_run_retention_days','smai_future_scenario_retention_days','smai_future_alert_retention_days','smai_future_incident_retention_days']:
    if token not in activator or token not in retention: errors.append(f'review7_retention_option_missing:{token}')
for token in ["table('future_runs')","table('scenario_models')","table('intelligence_alerts')","table('research_workspaces')"]:
    if token not in retention: errors.append(f'review7_retention_target_missing:{token}')
if 'expires_on' not in engine: errors.append('review7_research_expiry_engine_missing')
if 'smai_future_research_expiry_required' not in artifact or "'expires_at'=>$expiresAt" not in artifact:
    errors.append('review7_research_expiry_persistence_missing')
# Review-8 scheduler/idempotency invariants.
for token in ['scheduledEvidenceUuid','scheduled_bucket','INSERT IGNORE','feature_row_version','config_hash']:
    if token not in service: errors.append(f'review8_scheduler_guard_missing:{token}')
if "SELECT state,approved_by,requested_by,row_version,config_hash" not in service:
    errors.append('review8_scheduler_approval_recheck_missing')
# Review-5 API/governance invariants.
for token in ['IdempotencyGuard','private function mutation','smai_invalid_json','smai_request_too_large','X-Sabri-Trace-ID']:
    if token not in controller: errors.append(f'review5_api_guard_missing:{token}')
for token in ['logInOpenTransaction','START TRANSACTION','smai_future_activation_request_pending']:
    if token not in activation: errors.append(f'review5_activation_atomicity_missing:{token}')
if service.count('smai_future_actor_required') < 4:
    errors.append('review5_service_actor_validation_incomplete')
# Review-6 reproducibility/stateful artifact invariants.
artifact=text('src/Domain/FutureArtifactStore.php')
for token in ['FutureArtifactStore','feature_row_version','config_hash','schema_version','contract_version']:
    if token not in service: errors.append(f'review6_run_binding_missing:{token}')
for token in ['CF05-FUT-021','scenario_models','CF05-FUT-025','privacy_budgets','CF05-FUT-029','research_workspaces','CF05-FUT-038','transparency_records','FOR UPDATE','budget_row_version']:
    if token not in artifact: errors.append(f'review6_artifact_persistence_missing:{token}')
if manifest.get('version')!='1.0.0-rc.9': errors.append('manifest_version_not_rc9')
if manifest.get('schema_version')!='1.4.1': errors.append('manifest_schema_not_1_4_1')
if manifest.get('contract_version')!='1.4.0': errors.append('manifest_contract_not_1_4_0')
if 'contracts/future-feature.schema.json' not in manifest.get('public_contracts',[]): errors.append('future_contract_not_manifested')
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Future-40 check passed: 40 features, governed routes, persistence, activation gates and QA coverage.')
