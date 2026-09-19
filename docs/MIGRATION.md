# Migration and Cutover

1. Inventory current event/metric definitions, tables, options, cron hooks, provider mappings and companion contracts.
2. Run additive/idempotent schema migration under a lock; verify backup before any source-data operation.
3. Register immutable event, dataset and metric versions without changing native owner writes.
4. Dry-run bounded backfills; record source counts, transformed counts, rejected counts, cost and expected build hash.
5. Build shadow datasets and snapshots, apply deletion floors, compare with current approved reports and investigate every unexplained divergence.
6. Obtain privacy/domain approval, activate the shadow build atomically and retain the former build for rollback/reproduction.
7. Reconcile consumers, invalidate caches and explicitly migrate pinned metric versions.

No destructive source migration or direct companion-table write is allowed.
