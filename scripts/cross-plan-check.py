#!/usr/bin/env python3
from __future__ import annotations
import json
import pathlib
import re
import sys

root = pathlib.Path(__file__).resolve().parents[1]
errors: list[str] = []
required = [
    'src/Domain/PrivacyQueryPolicy.php',
    'src/Domain/QueryPrivacyGuard.php',
    'src/Domain/ExportControlService.php',
    'src/Domain/ReportControlService.php',
    'src/Http/GovernanceRestController.php',
    'docs/THREE-PLAN-TRACEABILITY.md',
]
for path in required:
    if not (root / path).is_file():
        errors.append(f'missing cross-plan control: {path}')

metric_schema = json.loads((root / 'contracts/metric-response.schema.json').read_text())
quality = set(metric_schema['properties']['quality_status']['enum'])
expected_quality = {'green', 'warning', 'degraded', 'unknown', 'suppressed', 'invalidated'}
if quality != expected_quality:
    errors.append(f'metric response quality contract mismatch: {sorted(quality)}')

query = (root / 'src/Domain/MetricQueryService.php').read_text()
for token in ['QueryPrivacyGuard', 'PrivacyQueryPolicy::effectiveMinimum', 'dimensions_fingerprint']:
    if token not in query:
        errors.append(f'missing query privacy control: {token}')

guard = (root / 'src/Domain/QueryPrivacyGuard.php').read_text()
for token in ['smai_differencing_risk', 'smai_privacy_budget_exceeded', 'smai_slice_budget_exceeded', "scope='privacy-query'"]:
    if token not in guard:
        errors.append(f'missing differencing/privacy-budget control: {token}')

routes = (root / 'src/Http/GovernanceRestController.php').read_text()
for route in ['/revoke', '/pause', '/resume', '/unsubscribe']:
    if route not in routes:
        errors.append(f'missing governed REST route: {route}')

css = (root / 'assets/css/insights.css').read_text()
markup = (root / 'src/Presentation/InsightsShortcode.php').read_text()
for class_name in ['smai-insights__card', 'smai-insights__value', 'smai-insights__definition-list']:
    if class_name not in css or class_name not in markup:
        errors.append(f'CSS/markup class contract mismatch: {class_name}')
if 'id="smai-dashboard-title"' in markup:
    errors.append('static duplicate dashboard title ID remains')
if 'wp_unique_id' not in markup:
    errors.append('unique component IDs are not generated')

activator = (root / 'src/Infrastructure/Activator.php').read_text()
for role in ['smai_analyst', 'smai_data_steward', 'smai_analytics_approver', 'smai_access_officer', 'smai_auditor', 'smai_recovery_operator']:
    if role not in activator:
        errors.append(f'missing least-privilege role: {role}')

trace = (root / 'docs/THREE-PLAN-TRACEABILITY.md').read_text() if (root / 'docs/THREE-PLAN-TRACEABILITY.md').exists() else ''
for marker in ['SSH-PMP-2026-v3.0', 'Consolidated All-Chats Recovered Directive Register 2.1', 'CF05-FR-001', 'CF05-FR-035', 'Islamic privacy', 'Hostinger staging']:
    if marker not in trace:
        errors.append(f'missing three-plan traceability marker: {marker}')

manifest = json.loads((root / 'MANIFEST.json').read_text())
if manifest.get('contract_version') != '1.2.0':
    errors.append('manifest contract version is not 1.2.0')
if manifest.get('version') != '1.0.0-rc.3':
    errors.append('manifest version is not 1.0.0-rc.3')

all_php = '\n'.join(path.read_text(errors='replace') for path in root.rglob('*.php'))
if re.search(r'\b(?:TODO|FIXME|not implemented)\b', all_php, re.I):
    errors.append('unfinished source marker found')

if errors:
    for error in errors:
        print('ERROR:', error, file=sys.stderr)
    sys.exit(1)
print('Three-plan cross-plan controls and consistency checks passed.')
