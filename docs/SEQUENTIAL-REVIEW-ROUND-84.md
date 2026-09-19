# Sequential Review Round 84 — Background job and scheduler execution boundary

The complete queue claim/dispatch lifecycle, every current job producer, scheduled report enqueue path, runtime worker gate and queue schema were reviewed before any correction. The defect ledger was frozen first.

## Frozen defect ledger (2)
1. `JobQueue::enqueue()` accepted any syntactically valid job type even though `JobRunner::dispatch()` supports a closed set. A typo or unsupported internal producer could therefore persist a job that can never execute successfully and only fails later through retry/dead-letter handling.
2. `ReportService::scheduleDue()` advanced report schedules and enqueued report jobs even when the CF-05 worker runtime was disabled. This could consume scheduled intervals during foundation/safe-mode operation and create stale work for later activation.

## Corrections after review completion
- Added an explicit closed job-type allowlist at queue ingress matching the worker dispatcher.
- Bounded idempotency-key input before hashing.
- Scheduled reports now return without advancing state unless the governed worker runtime is enabled.
- Added permanent runtime/queue invariants for both boundaries.

## Post-correction QA
The first correction commit exposed a defect in the newly extended invariant script itself: it referenced undefined Python variables after the pre-existing success/exit block. That regression was corrected within Round 84 and the invariant was integrated into the existing `e` failure ledger before continuing.

## Truth boundary
This is repository-source behavior. It does not establish the live cron state, deployed worker state, queue contents or production runtime mode.
