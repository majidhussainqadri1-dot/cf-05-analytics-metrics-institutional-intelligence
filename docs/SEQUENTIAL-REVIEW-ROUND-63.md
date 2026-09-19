# Sequential Review Round 63 — Restore-point consistency and verification race safety

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 64 begins.

## Frozen defect ledger (2)
1. Restore-point catalog/checkpoint/deletion/access evidence was captured before the restore transaction began, so concurrent writes could produce an internally inconsistent restore-point snapshot.
2. Restore verification loaded the restore point and evaluated database invariants before beginning the verification transaction, leaving a race window in which the persisted `verified` state could certify database conditions that had already changed.

## Corrections
- Restore-point evidence capture now runs inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT` transaction together with persistence and audit evidence.
- Restore verification now expires due access first, then performs restore-point locking, invariant evaluation, state update and audit within one consistent-snapshot transaction.

## Truth boundary
Repository-source review and automated QA only; this does not establish that a backup is currently restorable in staging or live. Actual restore rehearsal remains an external lifecycle gate.
