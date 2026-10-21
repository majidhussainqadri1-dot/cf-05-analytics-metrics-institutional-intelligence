# Review Round 23 — Backfills, lineage and governed rebuilds

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. backfill_planned uses a nested AuditLogger transaction inside an already-open backfill transaction.
2. backfill_dry_run uses a nested AuditLogger transaction inside an already-open backfill transaction.
3. backfill_approved uses a nested AuditLogger transaction inside an already-open backfill transaction.
4. Backfill row materialization ignores lineage-write failures and can continue toward compared state.
5. Backfill dataset-row INSERT failures are not distinguished from harmless duplicate inserts.
6. Backfill activation/rollback commits state before best-effort audit evidence is written.
7. Backfill planning accepts loose strtotime date syntax instead of strict governed timestamps.

## Corrections
- Converted backfill transaction audits to caller-owned atomic audit writes.
- Made row, lineage, checkpoint and final comparison persistence failures fail closed.
- Made activation and rollback state changes atomic with their audit evidence.
- Made backfill window parsing strict RFC3339.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
