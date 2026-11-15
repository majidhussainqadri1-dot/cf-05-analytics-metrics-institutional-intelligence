#!/usr/bin/env python3
"""V12.3 acceptance gate cannot be bypassed by status booleans or fake parity."""
from __future__ import annotations

import copy
import importlib.util
import json
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
path = ROOT / "scripts/v123-acceptance-gate.py"
spec = importlib.util.spec_from_file_location("cf05_v123_acceptance", path)
if spec is None or spec.loader is None:
    raise RuntimeError("Cannot import acceptance gate")
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
original = json.loads((ROOT / "contracts/cf05-v12.3-acceptance-matrix.json").read_text(encoding="utf-8"))

passed = 0
failed = 0


def check(condition: bool, name: str) -> None:
    global passed, failed
    print(("PASS" if condition else "FAIL") + " V12.3 acceptance gate " + name)
    if condition:
        passed += 1
    else:
        failed += 1


def bad(data: dict, needle: str) -> bool:
    return any(needle in entry for entry in module.audit(data)[0])


failures, pending = module.audit(original)
check(failures == [], "exact source acceptance matrix valid")
check(len(original["core"]) == 35, "35 core requirements")
check(len(original["future"]) == 40, "40 separately disabled future features")
check(len(original["journeys"]) == 6, "6 native journeys")
check(len(original["traffic"]) == 12, "12 traffic acceptance controls")
check(len(original["disease"]) == 14, "14 disease acceptance controls")
check(len(original["release_gates"]) == 19, "19 independent production release gates")
check(len(pending) == 35 + 6 + 12 + 14 + 19 + 5, "all initial staging/live gates remain pending")

def mutation(group: str, index: int, field: str, value: object) -> dict:
    data = copy.deepcopy(original)
    data[group][index][field] = value
    return data

check(bad(mutation("core", 0, "id", "CF05-FR-1"), "incorrect canonical ID"), "zero padding cannot drift")
check(bad(mutation("traffic", 0, "id", "AT-TA-001"), "incorrect canonical ID"), "two-digit traffic ID remains canonical")
check(bad(mutation("disease", 0, "id", "AT-DI-001"), "incorrect canonical ID"), "two-digit disease ID remains canonical")
check(bad(mutation("future", 0, "runtime_state", "production_active"), "future runtime must remain disabled"), "future features cannot self activate")
check(bad(mutation("core", 0, "acceptance_status", "accepted"), "accepted without dated independent evidence"), "core acceptance requires evidence")
check(bad(mutation("traffic", 1, "acceptance_status", "accepted"), "accepted without dated independent evidence"), "traffic acceptance requires evidence")
check(bad(mutation("release_gates", 0, "status", "accepted"), "claimed acceptance without evidence"), "release gate requires independent evidence")
check(bad(mutation("core", 0, "acceptance_status", True), "invalid acceptance state"), "boolean success cannot substitute for acceptance")
check(bad(mutation("core", 0, "source_status", "live_ready"), "falsely certified"), "source candidate cannot self certify")

fake = copy.deepcopy(original)
fake["live_truth"]["artifact_checksum"] = "source-release-checksum"
check(bad(fake, "invalid installed artifact checksum"), "source checksum is not installed artifact proof")
fake = copy.deepcopy(original)
fake["live_truth"]["deployed_version"] = 7
check(bad(fake, "invalid live truth field"), "invalid deployed version rejected")

now = datetime.now(timezone.utc)
valid_evidence = {
    "reference": "review:independent_staging_20261115",
    "environment": "staging",
    "actor": "reviewer_one",
    "recorded_at": (now - timedelta(days=1)).isoformat(),
    "code_sha": "a" * 40,
}
check(module.evidence_valid([valid_evidence]), "bounded structured staging evidence syntax accepted")
fake_evidence = dict(valid_evidence)
fake_evidence["reference"] = "https://user:password@example.com/secret"
check(not module.evidence_valid([fake_evidence]), "credential-bearing evidence reference rejected")
fake_evidence = dict(valid_evidence)
fake_evidence["recorded_at"] = (now + timedelta(days=1)).isoformat()
check(not module.evidence_valid([fake_evidence]), "future-dated review rejected")
fake_evidence = dict(valid_evidence)
fake_evidence["actor"] = ""
check(not module.evidence_valid([fake_evidence]), "unaccountable approval denied")
fake_evidence = dict(valid_evidence)
fake_evidence["code_sha"] = "HEAD"
check(not module.evidence_valid([fake_evidence]), "unfrozen source SHA denied")

test_accepted = mutation("core", 0, "acceptance_status", "accepted")
test_accepted["core"][0]["evidence"] = [valid_evidence]
check(module.audit(test_accepted)[0] == [], "single individually evidenced control accepted without resolving unrelated pending gates")
check(len(module.audit(test_accepted)[1]) == len(pending)-1, "only one evidence-gated control cleared")

print(f"CF-05 V12.3 acceptance gate tests: {passed}/{passed+failed}")
raise SystemExit(0 if failed == 0 else 1)
