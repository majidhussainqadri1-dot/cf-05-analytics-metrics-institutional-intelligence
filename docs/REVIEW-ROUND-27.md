# Review Round 27 — Job queue leases, retries and scheduling

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. Expired running jobs are reclaimable without a max-attempt fence, so crash-loop jobs can exceed max_attempts indefinitely.
2. Job enqueue accepts an arbitrary runAt string without validating a database-safe governed timestamp.

## Corrections
- Fenced expired max-attempt jobs into dead-letter state before claims and prevented further reclaim.
- Validated scheduled job run times before persistence.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
