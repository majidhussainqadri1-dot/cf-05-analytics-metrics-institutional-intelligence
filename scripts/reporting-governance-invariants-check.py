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
if "max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20))" not in files['DashboardService.php']: e.append('dashboard disclosure omits metric-specific cohort floor')
if "is_float($dimensions[$dimension]) && !is_finite($dimensions[$dimension])" not in files['DashboardService.php']: e.append('dashboard accepts non-finite dimension values')
if "Dashboard audience capabilities must be an array." not in files['DashboardService.php'] or "Dashboard audience user_ids must be an array." not in files['DashboardService.php']: e.append('dashboard audience collection types are not strict')
if "is_float($value) && !is_finite($value)" not in files['ReportService.php'] or "is_float($value) && !is_finite($value)" not in files['ReportControlService.php']: e.append('report dimensions accept non-finite values')
if "user_can($actorUserId, 'smai_manage_reports')" not in files['ReportService.php']: e.append('report creation lacks service-layer manage_reports authorization')
if "user_can($actorUserId, 'smai_approve_catalog')" not in files['ReportService.php']: e.append('report activation lacks service-layer approval authorization')
if files['ReportControlService.php'].count("user_can($actorUserId, 'smai_manage_reports')") < 3: e.append('report control manage_reports authorization incomplete')
if "user_can($actorUserId, 'smai_approve_catalog')" not in files['ReportControlService.php']: e.append('report resume lacks service-layer approval authorization')
if "user_can($actorUserId, 'smai_manage_access')" not in files['ReportControlService.php']: e.append('report revocation lacks service-layer manage_access authorization')
if e: print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Reporting governance invariants check passed.')
