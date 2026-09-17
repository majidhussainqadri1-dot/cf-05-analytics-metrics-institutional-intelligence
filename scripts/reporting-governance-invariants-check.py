#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
files={n:(r/'src/Domain'/n).read_text(encoding='utf-8') for n in ['AccessProjectService.php','DashboardService.php','ExportService.php','ExportControlService.php','ReportService.php','ReportControlService.php','NarrativeService.php']}
e=[]
bad_actions={
    'AccessProjectService.php':['analytics_access_requested','analytics_access_granted','analytics_access_revoked','analytics_access_expired'],
    'DashboardService.php':['dashboard_registered','dashboard_activated'],
    'ExportService.php':['analytics_export_requested','analytics_export_ready'],
    'ExportControlService.php':['analytics_export_revoked'],
}
for n,actions in bad_actions.items():
    for action in actions:
        if f"audit->log('{action}'" in files[n] or f"audit->log(\n            '{action}'" in files[n]:
            e.append(f'{n}: mutation audit {action} is not using the caller transaction')
if "smai_audit'))" in files['ExportControlService.php']: e.append('read-only auditor still authorizes export mutation internally')
if "hash('sha256', 'user:'" in files['ReportService.php'] or "hash('sha256', 'user:'" in files['ReportControlService.php']: e.append('report recipient identity uses unkeyed hash')
if 'report_delivery_accessed' not in files['ReportService.php']: e.append('report delivery access is not auditable fail-closed')
if 'logInOpenTransaction' not in files['NarrativeService.php']: e.append('narrative mutations are not audit-atomic')
if e: print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Reporting governance invariants check passed.')
