# Sequential Review Round 56 — Idempotency identity integrity, expiry reuse and stored-evidence parity

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 57 begins.

## Frozen defect ledger (3)
1. Idempotency scope, actor reference and request hash were not validated before identity creation; scope/actor were truncated only at storage time while the primary identity hash used the untruncated inputs.
2. An expired idempotency row blocked safe reuse until asynchronous retention cleanup, even though its protection window had ended.
3. Release/finalization derived identities from unchecked scope/actor/key values and did not bind the state mutation back to the stored scope/actor evidence.

## Corrections
- Added closed-format validation for scope/actor, bounded keys, and exact SHA-256 request hashes before idempotency state creation.
- Removed storage-time truncation divergence: validated scope/actor are stored exactly as hashed.
- Added conditional expired-row recycling with a bounded retry to preserve concurrency safety.
- Bound release/finalization to stored scope and actor values and validated response status codes.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
