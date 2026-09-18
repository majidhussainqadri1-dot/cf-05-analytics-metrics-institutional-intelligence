#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
future=(root/'src/Domain/FutureFeatureService.php').read_text(encoding='utf-8')
gov=(root/'src/Http/GovernanceRestController.php').read_text(encoding='utf-8')
access=(root/'src/Domain/AccessProjectService.php').read_text(encoding='utf-8')
dash=(root/'src/Domain/DashboardService.php').read_text(encoding='utf-8')
rest=(root/'src/Http/RestController.php').read_text(encoding='utf-8')
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
if errors:
    print('\n'.join(errors), file=sys.stderr); sys.exit(1)
print('Authorization invariants check passed.')
