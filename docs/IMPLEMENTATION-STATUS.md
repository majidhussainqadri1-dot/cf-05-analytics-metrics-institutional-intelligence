# CF-05 Implementation Status — Foundation 0.1.0

This record separates plan requirements from current code evidence.

## Implemented in the initial source candidate

- **CF05-FR-001–006:** schema registry, envelope validation, purpose/consent/minor gates, allowlist-first redaction/tokenization, dedupe/replay controls and quarantine foundation.
- **CF05-FR-013–016:** semantic metric registration, lifecycle state policy, allowlisted dimensions, version-pinned responses, freshness/quality fields and cohort threshold foundation.
- **CF05-FR-019–021:** capability-scoped REST surfaces, bounded metric query inputs, exact snapshot lookup and minimum-cohort suppression foundation.
- **CF05-FR-030:** purpose-bounded raw/quarantine retention jobs foundation.
- **CF05-FR-033:** minimized, concurrency-safe hash-chained audit evidence foundation.
- **CF05-FR-035:** schema/version health evidence and fail-closed runtime activation foundation.

## Partially implemented

- **CF05-FR-007–012:** database structures and lineage fields exist; full derivative warehouse models, backfill engine, reconciliation and provider/region execution are not complete.
- **CF05-FR-022–024:** report/export entities and contracts are planned/documented; secure file generation, delivery and narrative insight workflow are not complete.
- **CF05-FR-031–032:** deletion/access project tables exist; end-to-end propagation, automatic expiry and provider reconciliation are not complete.

## Not yet implemented

- Full warehouse/lake projection engine and reproducible transformation runner.
- Snapshot computation and publication workflow.
- Scheduled reports and secure export file lifecycle.
- Experiment registry, statistical analysis and guardrail execution contracts.
- Complete deletion/anonymization propagation across models, exports and providers.
- Provider migration/exit, restore/rebuild and report parity engine.
- File 00/20/23/24/25/26 live contract acceptance.
- Hostinger staging, browser/accessibility evidence, load/penetration testing and operational governance.

No pending item is represented as live or production-complete.
