# Sequential Review Round 30 — Access projects, expiry and transaction atomicity

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (4)
1. Access-project external expiry accepts loose/relative strtotime syntax instead of strict RFC3339 input.
2. Access request/approval/revocation/expiry transactions can proceed without verifying that START TRANSACTION succeeded.
3. Access lifecycle paths can report success or increment expiry counts without verifying COMMIT success.
4. Scheduled access expiry does not fail closed on commit failure.

## Corrections
- Required strict RFC3339 expiry input.
- Made request/approval/revocation/expiry transaction start and commit fail closed.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
