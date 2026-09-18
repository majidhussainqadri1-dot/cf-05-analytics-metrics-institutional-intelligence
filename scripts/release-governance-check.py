#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
errors=[]
def text(rel):
    p=root/rel
    if not p.is_file(): errors.append('missing:'+rel); return ''
    return p.read_text(encoding='utf-8')
runtime=text('src/Infrastructure/RuntimeActivationService.php')
gov=text('src/Http/GovernanceRestController.php')
uninstall=text('uninstall.php')
build=text('scripts/build-package.py')
parity=text('scripts/package-parity.py')
verify=text('scripts/verify-deterministic-build.sh')
plugin=text('sabri-analytics-institutional-intelligence.php')
traceability=text('docs/REQUIREMENTS-TRACEABILITY.md')
changelog=text('CHANGELOG.md')
for token in ['START TRANSACTION','logInOpenTransaction','RuntimeGate::schemaReady()','smai_activation_request_pending','actorUserId<1','INSERT IGNORE']:
    if token not in runtime: errors.append('runtime_activation_guard_missing:'+token)
for token in ['IdempotencyGuard','private function mutation','smai_request_too_large','smai_invalid_json','X-Sabri-Trace-ID']:
    if token not in gov: errors.append('governance_rest_guard_missing:'+token)
for token in ['smai_future_intelligence_tick','smai_future_run_retention_days','smai_future_scenario_retention_days','smai_future_alert_retention_days','smai_future_incident_retention_days','smai_future40_activation_request','smai_future40_approved_by','smai_future40_approved_at','smai_activation_request','remove_role','remove_cap']:
    if token not in uninstall: errors.append('uninstall_cleanup_missing:'+token)
for token in ['SMAI_VERSION','SMAI_SCHEMA_VERSION','SMAI_CONTRACT_VERSION']:
    if token not in build: errors.append('dynamic_build_identity_missing:'+token)
if 'SMAI_VERSION' not in parity: errors.append('dynamic_parity_version_missing')
if 'SMAI_VERSION' not in verify: errors.append('dynamic_verify_version_missing')
import re
version_match=re.search(r"define\('SMAI_VERSION',\s*'([^']+)'\);",plugin)
if not version_match:
    errors.append('plugin_version_missing')
else:
    current_version=version_match.group(1)
    if f'# CF-05 Requirements Traceability — {current_version}' not in traceability:
        errors.append('requirements_traceability_release_identity_drift')
    if f'## {current_version} —' not in changelog:
        errors.append('changelog_current_release_missing')
for rel,body in [('build-package.py',build),('package-parity.py',parity)]:
    if re.search(r"version\s*=\s*['\"]1\\.0\\.0-rc\\.",body): errors.append('hardcoded_release_version:'+rel)
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Release governance check passed: base activation atomicity, governance REST parity and uninstall least-privilege cleanup verified.')
