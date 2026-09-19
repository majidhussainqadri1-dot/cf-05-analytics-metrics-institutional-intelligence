# CF-05 Review Round 8 — Scheduler Idempotency and Provenance

Audit completed in full before corrections; the defect ledger was frozen first.

## Defects found
1. Hourly Future-40 scheduled evidence could duplicate during cron retry or overlap.
2. Scheduled execution trusted `state=active` without re-checking persisted independent-approval integrity.
3. Scheduled evidence did not pin the feature row version and approved configuration hash.
4. Automated QA did not guard scheduler idempotency/provenance.

## Corrections
- Added deterministic UUID identity per feature/hour/config-version and `INSERT IGNORE` replay safety.
- Re-checks active state, independent approver/requester separation, row version and config hash under row lock.
- Scheduled audit/evidence now records time bucket, row version and config hash.
- Added static QA invariants.

No external delivery or autonomous decision was introduced. Repository/source evidence only; staging/live remain separate.
