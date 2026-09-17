#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]

# 1) Active Future-40 execution must fail closed if global Future-40 approval is later disabled.
p = root / 'src/Domain/FutureFeatureService.php'
s = p.read_text(encoding='utf-8')
old = "            if(!$dryRun&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','Active execution requires valid independent approval evidence.',['status'=>409]));\n            if(!$dryRun&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled.',['status'=>409]));"
new = "            if(!$dryRun&&($row['approved_by']===null||(int)$row['approved_by']<1||(int)$row['approved_by']===(int)$row['requested_by']))return $this->rollbackError(new WP_Error('smai_future_approval_integrity','Active execution requires valid independent approval evidence.',['status'=>409]));\n            if(!$dryRun&&!FutureActivationService::isApproved())return $this->rollbackError(new WP_Error('smai_future_activation_gate','Future-40 activation evidence is no longer approved.',['status'=>409]));\n            if(!$dryRun&&!RuntimeGate::queryEnabled())return $this->rollbackError(new WP_Error('smai_future_runtime_gate','Base CF-05 runtime is not enabled.',['status'=>409]));"
if old not in s:
    raise SystemExit('FutureFeatureService target not found')
p.write_text(s.replace(old, new, 1), encoding='utf-8')

# 2) Read-only auditor must never hold an export-revocation mutation path.
p = root / 'src/Http/GovernanceRestController.php'
s = p.read_text(encoding='utf-8')
old = "'permission_callback'=>static fn():bool=>current_user_can('smai_export_metrics')||current_user_can('smai_manage_access')||current_user_can('smai_audit'),"
new = "'permission_callback'=>static fn():bool=>current_user_can('smai_export_metrics')||current_user_can('smai_manage_access'),"
if old not in s:
    raise SystemExit('GovernanceRestController target not found')
p.write_text(s.replace(old, new, 1), encoding='utf-8')

# 3) Permanent regression gate.
check = root / 'scripts/authorization-invariants-check.py'
check.write_text("""#!/usr/bin/env python3
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
    print('\\n'.join(errors), file=sys.stderr); sys.exit(1)
print('Authorization invariants check passed.')
""", encoding='utf-8')

p = root / 'scripts/qa.sh'
s = p.read_text(encoding='utf-8')
needle = "python3 scripts/release-governance-check.py\n"
if 'authorization-invariants-check.py' not in s:
    if needle not in s:
        raise SystemExit('qa.sh insertion point missing')
    s = s.replace(needle, needle + "python3 scripts/authorization-invariants-check.py\n", 1)
    p.write_text(s, encoding='utf-8')

(root/'docs/REVIEW-ROUND-11.md').write_text("""# Review Round 11 — Activation and Authorization Re-Audit\n\nThe round was completed before corrections began.\n\n## Confirmed defects\n\n1. An already-active Future-40 feature re-checked base runtime state but did not re-check the global Future-40 activation approval on each non-dry execution. A later global disable could therefore leave a direct active-run path open until each feature was separately disabled.\n2. The export revocation route admitted `smai_audit`; that capability belongs to the read-only auditor role and must not authorize mutations.\n\n## Corrections\n\n- Active Future-40 execution now fails closed unless `FutureActivationService::isApproved()` remains true.\n- Export revocation now requires export/access management authority; the read-only auditor path was removed.\n- A permanent authorization invariants QA check prevents regression.\n\nStaging/live state is not asserted by this source review.\n""", encoding='utf-8')
print('Review 11 corrections applied.')
