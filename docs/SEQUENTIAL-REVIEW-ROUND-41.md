# Sequential Review Round 41 — Job leases, scheduling and pipeline transaction fencing

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (5)
1. JobQueue scheduled run_at accepts normalized invalid SQL-calendar values instead of exact UTC datetimes.
2. Job completion is not fenced by an unexpired worker lease, so a worker may complete after its lease deadline before another worker reclaims the job.
3. Job failure persistence is likewise not fenced by lease expiry.
4. Pipeline active-build creation does not verify that its transaction actually started.
5. Pipeline active-build reuse/creation can return success without verifying commit success.

## Corrections
- Made job scheduling calendar-exact.
- Fenced completion/failure by unexpired leases.
- Made pipeline active-build transaction start/commit fail closed.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
