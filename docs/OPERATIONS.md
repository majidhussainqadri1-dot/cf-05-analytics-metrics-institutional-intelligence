# Operations

## Scheduled work

- `smai_run_jobs`: bounded leased job processing every five minutes when evidence-bound workers are enabled.
- `smai_daily_retention`: event, row, quarantine, export, delivery, nonce, rate-limit and completed-job retention.
- `smai_schedule_reports`: approved due-report enqueueing.
- `smai_access_expiry`: automatic project expiry and child revocation.

## Golden signals

Traffic, latency, errors, saturation, queue lag/dead letters, schema drift, freshness, completeness, quality failures, deletion reconciliation, access expiry, provider health and audit-chain verification.

Unknown telemetry is never rendered as green. High/critical quality issues block affected publication. Incident response preserves evidence and uses native owner commands for any product stop; CF-05 never silently mutates domain policy.
