#!/usr/bin/env python3
"""CF-05 Master Plan V12.3 strict acceptance-evidence gate.

Automated source QA is permitted on a candidate without claiming a live release.
--validate validates the entire versioned plan matrix.
--release-ready additionally requires owner-reviewed staging/live evidence.
Even a structurally green matrix does not itself authenticate the references:
owners must independently inspect the real deployed and database state.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "contracts/cf05-v12.3-acceptance-matrix.json"
GROUPS = {
    "core": ("CF05-FR", 35, 3),
    "future": ("CF05-FUT", 40, 3),
    "journeys": ("CF05-NJ", 6, 2),
    "traffic": ("AT-TA", 12, 2),
    "disease": ("AT-DI", 14, 2),
}
RELEASE_FIELDS = (
    "deployed_version",
    "db_version",
    "migration_state",
    "artifact_checksum",
    "live_retest",
)


def evidence_valid(value: object) -> bool:
    """Require accountable bounded structured evidence, never an empty claim."""
    if not isinstance(value, list) or not value or len(value) > 20:
        return False
    for item in value:
        if not isinstance(item, dict) or set(item) != {
            "reference", "environment", "actor", "recorded_at", "code_sha"
        }:
            return False
        if not all(isinstance(item[field], str) for field in item):
            return False
        if not re.fullmatch(r"[A-Za-z0-9_.:-]{12,180}", item["reference"]):
            return False
        if item["environment"] not in ("staging", "production"):
            return False
        if not re.fullmatch(r"[A-Za-z0-9_.-]{3,100}", item["actor"]):
            return False
        if not re.fullmatch(r"[0-9a-f]{40}", item["code_sha"]):
            return False
        try:
            instant = datetime.fromisoformat(item["recorded_at"].replace("Z", "+00:00"))
        except ValueError:
            return False
        if instant.tzinfo is None or instant.astimezone(timezone.utc) > datetime.now(timezone.utc):
            return False
    return True


def audit(data: object) -> tuple[list[str], list[str]]:
    failures: list[str] = []
    pending: list[str] = []
    if not isinstance(data, dict) or data.get("schema") != "cf05-v12.3-acceptance-evidence-1.0.0":
        return (["unknown acceptance matrix schema"], pending)
    if data.get("governing_plan") != "Sabri-Website-Master-Plan-V12.3.docx":
        failures.append("governing source is not exact V12.3")
    for key, (prefix, expected, digits) in GROUPS.items():
        entries = data.get(key)
        if not isinstance(entries, list) or len(entries) != expected:
            failures.append(f"{key}: expected {expected} records")
            continue
        for i, entry in enumerate(entries, 1):
            target = f"{prefix}-{i:0{digits}d}"
            if not isinstance(entry, dict) or entry.get("id") != target:
                failures.append(f"{key}[{i}]: incorrect canonical ID {target}")
                continue
            if not isinstance(entry.get("requirement"), str) or not entry["requirement"].strip():
                failures.append(f"{target}: missing governing requirement name")
            if key == "future":
                if entry.get("source_status") != "coded_conditional":
                    failures.append(f"{target}: conditional source state drift")
                if entry.get("runtime_state") != "disabled_pending_separate_approval":
                    failures.append(f"{target}: future runtime must remain disabled")
                if entry.get("evidence") != []:
                    failures.append(f"{target}: future activation evidence not governed here")
                continue
            if key == "core" and entry.get("source_status") != "candidate":
                failures.append(f"{target}: source implementation falsely certified")
            status = entry.get("acceptance_status")
            if status == "accepted":
                if not evidence_valid(entry.get("evidence")):
                    failures.append(f"{target}: accepted without dated independent evidence")
            elif status == "pending" and entry.get("evidence") == []:
                pending.append(target)
            else:
                failures.append(f"{target}: invalid acceptance state/evidence combination")

    gates = data.get("release_gates")
    if not isinstance(gates, list) or len(gates) != 19:
        failures.append("release gate inventory must contain exactly 19 controls")
    else:
        expected_gates = [
            "exact_deployed_runtime", "db_schema_migration", "deployment_parity",
            "file00_consent", "file23_native", "file24_assurance", "file20_shell",
            "file25_visual", "native_education", "finance_clinical",
            "other_owner_contracts", "traffic_at", "disease_at", "privacy_erasure",
            "performance", "security", "restore", "founder_signoff", "live_retest",
        ]
        for i, (entry, required_id) in enumerate(zip(gates, expected_gates)):
            if not isinstance(entry, dict) or entry.get("id") != required_id:
                failures.append(f"release gate index {i}: missing {required_id}")
                continue
            status = entry.get("status")
            if status == "accepted":
                if not evidence_valid(entry.get("evidence")):
                    failures.append(f"{required_id}: claimed acceptance without evidence")
            elif status == "pending" and entry.get("evidence") == []:
                pending.append(required_id)
            else:
                failures.append(f"{required_id}: invalid acceptance state")

    live = data.get("live_truth")
    if not isinstance(live, dict) or set(live) != set(RELEASE_FIELDS):
        failures.append("missing exact-deployed-state truth ledger")
    else:
        for field in RELEASE_FIELDS:
            value = live[field]
            if value is None:
                pending.append("live_truth." + field)
            elif not isinstance(value, str) or len(value) < 3 or len(value) > 180:
                failures.append("invalid live truth field: " + field)
            elif field == "artifact_checksum" and not re.fullmatch(r"[0-9a-f]{64}", value):
                failures.append("invalid installed artifact checksum")
    return failures, pending


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--validate", action="store_true", help="validate candidate/source matrix without claiming release")
    mode.add_argument("--release-ready", action="store_true", help="require full authenticated acceptance evidence")
    args = parser.parse_args()
    try:
        data = json.loads(MATRIX.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        print(f"CF-05 acceptance matrix unreadable: {error}", file=sys.stderr)
        return 2
    failures, pending = audit(data)
    if failures:
        print("CF-05 V12.3 INVALID ACCEPTANCE MATRIX:", file=sys.stderr)
        for error in failures:
            print(" - " + error, file=sys.stderr)
        return 2
    if args.release_ready:
        if pending:
            print(f"CF-05 RELEASE BLOCKED: {len(pending)} unaccepted/unknown controls", file=sys.stderr)
            for key in pending:
                print(" - " + key, file=sys.stderr)
            return 3
        print("CF-05 matrix structurally release-ready; independently verify every evidence reference and live parity.")
        return 0
    print(
        "CF-05 V12.3 matrix valid: 35 core, 40 conditional future, 6 native journeys, "
        f"12 Traffic Analytics, 14 Disease Intelligence; {len(pending)} acceptance/live facts pending."
    )
    print("Candidate source QA is not staging or live approval.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
