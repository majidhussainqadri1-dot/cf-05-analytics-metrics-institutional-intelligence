# Sequential Review Round 90 — Migration, activation lock and reapproval safety

Schema migration, activation/deactivation, plugin boot upgrade behavior, rollback documentation, uninstall cleanup and release-governance checks were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (4)
1. The schema migration used a stale-option delete/reacquire lock pattern with a race window: two workers observing the same stale lock could delete/reacquire across one another and enter migration concurrently.
2. Plugin activation used the same stale-option lock pattern and therefore had the same concurrency race.
3. A migration failure persisted an error marker but did not durably force the runtime state and worker/approval flags into Safe Mode.
4. A successful schema-version change could preserve a prior runtime/Future-40 approval. After the new schema became ready, a previously active state could resume without a fresh post-upgrade activation decision.

## Corrections after review completion
- Replaced both stale option locks with database advisory locks using `GET_LOCK`/`RELEASE_LOCK`, scoped by database/prefix identity.
- Migration failure now clears runtime approval/evidence/worker state and Future-40 approval state and persists base runtime Safe Mode.
- Any actual schema-version change invalidates prior base/Future activation approvals. A fresh install remains `foundation_disabled`; an upgrade remains `safe_mode` until explicitly reapproved.
- Extended release-governance QA to protect lock and fail-closed/reapproval behavior.

## Truth boundary
These controls govern repository migration code. They do not establish the live database schema, migration history, active MySQL connection behavior or deployed migration result.
