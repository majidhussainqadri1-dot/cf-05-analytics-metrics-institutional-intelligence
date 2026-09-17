#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
future=(root/'src/Domain/FutureFeatureService.php').read_text(encoding='utf-8')
gov=(root/'src/Http/GovernanceRestController.php').read_text(encoding='utf-8')
errors=[]
if "if(!$dryRun&&!FutureActivationService::isApproved())" not in future:
    errors.append('active Future-40 execution does not re-check global Future-40 approval')
revoke=gov.split("'/exports/(?P<uuid>[0-9a-fA-F-]{36})/revoke'",1)[-1].split(']);',1)[0]
if "smai_audit" in revoke:
    errors.append('read-only auditor can mutate export revocation')
if errors:
    print('\n'.join(errors), file=sys.stderr); sys.exit(1)
print('Authorization invariants check passed.')
