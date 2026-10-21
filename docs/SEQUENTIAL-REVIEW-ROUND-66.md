# Sequential Review Round 66 — Background-job retry idempotency and asynchronous state recovery

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 67 begins.

## Frozen defect ledger (8)
1. An export worker retry rejected an export left in `building` by a crashed/expired prior worker.
2. The first recoverable export-build exception immediately marked the export `failed` and cleared its token even though the governed job queue still intended to retry it.
3. If export generation committed but queue-completion persistence failed, a retry rejected the already-`ready` export instead of completing idempotently.
4. If privacy deletion committed but queue-completion persistence failed, a retry rejected the already-`completed` deletion job.
5. If a backfill reached `compared` but queue-completion persistence failed, a retry rejected the completed comparison.
6. Shadow-build insertion and binding to the backfill record were separate commits, permitting an orphan shadow build if the second write failed.
7. Backfill activation did not check whether `START TRANSACTION` succeeded before mutating active-build state.
8. Backfill rollback had the same unchecked transaction-start defect.

## Corrections
- JobRunner now passes bounded internal attempt/max-attempt provenance to handlers.
- Export builds accept governed retry from `building`, treat an already complete `ready` artifact idempotently, preserve the download token on recoverable attempts, and mark/clear it only on the final failed attempt.
- Completed deletion and compared backfill handlers now return their persisted outcome idempotently.
- Shadow-build creation and backfill binding now share one locked transaction.
- Backfill activation and rollback now fail closed when transaction start fails.

## Truth boundary
Repository-source review and automated QA only; asynchronous behavior has not yet been proven against staging cron/worker infrastructure.
