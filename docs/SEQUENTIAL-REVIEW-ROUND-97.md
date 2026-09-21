# Sequential Review Round 97 — Backfill transaction and activation integrity

Backfill planning, dry-run, independent approval, shadow-build projection, correction handling, comparison, activation/rollback and window parsing were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (4)
1. Plan, dry-run and approval paths started and committed database transactions without checking whether START TRANSACTION or COMMIT actually succeeded.
2. Backfill projection ignored failure of the correction-row UPDATE and could continue with an internally inconsistent shadow build.
3. Activation ignored failure while fencing the currently active build; a later successful activation write could therefore risk multiple active builds if the fencing statement failed.
4. Backfill controls silently coerced non-integer/non-boolean JSON values, and window parsing could normalize invalid calendar dates or invalid UTC offsets instead of rejecting them exactly.

## Corrections after review completion
- Plan, dry-run and approval transactions now fail closed on both transaction start and commit failure.
- Correction projection failures now abort the job.
- Existing active-build fencing is checked before activating the shadow build.
- Numeric controls require non-negative JSON integers and the hash-match control requires a JSON boolean.
- Backfill RFC3339 parsing now validates calendar dates and offset ranges before normalization.
- Permanent pipeline/backfill invariants were extended for these boundaries.

## Truth boundary
This is repository rebuild-governance evidence only. It does not establish live backfill jobs, active-build state, existing dataset-row consistency, database transaction health or deployed rebuild history.
