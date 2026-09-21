# Sequential Review Round 99 — Retention clock and quarantine-boundary fidelity

Raw-event retention, effective-dated warehouse retention, quarantine retention, export/delivery expiry, operational evidence cleanup and Future-40 derivative retention were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (2)
1. Dataset-row retention used `created_at` rather than the row's governed `effective_from` fact time. A backfill of old facts could therefore restart the retention clock and retain modeled facts longer than the dataset contract allows.
2. The configured quarantine retention period applied only to `resolved`/`discarded` rows. An old `open` quarantine item could remain indefinitely despite the explicit short quarantine-retention setting.

## Corrections after review completion
- Dataset-row retention is now evaluated against `effective_from`, preserving retention semantics for backfilled historical facts.
- Quarantine retention now applies to all quarantine states after the configured bound; unresolved age no longer silently overrides the retention policy.
- Permanent deletion/retention invariants were extended for both controls.

## Truth boundary
This is repository retention-policy evidence only. It does not establish the age or state of live rows, whether cron actually executes, the deployed retention options, or production purge results.
