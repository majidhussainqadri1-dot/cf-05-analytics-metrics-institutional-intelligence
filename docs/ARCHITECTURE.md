# CF-05 Architecture

## Governing invariant

CF-05 is an analytical projection and decision-support owner. Native modules remain authoritative for identity, domain objects, publication, moderation, clinic, clinical, financial, messaging, search and recommendation decisions.

## Runtime planes

1. **Contract plane:** immutable event, dataset and metric versions with governed state transitions.
2. **Ingestion plane:** service HMAC, replay/rate controls, schema/purpose/consent/minor/region gates, pseudonymization and quarantine.
3. **Warehouse plane:** effective-dated derivative rows, active shadow builds, checkpoints, lineage, quality and backfills.
4. **Semantic plane:** exact metric definitions and reproducible snapshots with cohort, dimension, freshness, quality, uncertainty and caveat controls.
5. **Consumption plane:** project-scoped dashboards, reports, encrypted exports, narratives and File 23/26 contracts.
6. **Experiment plane:** externally owned assignment facts, aggregate analyses, guardrails and human decisions.
7. **Lifecycle plane:** retention, deletion reconciliation, provider exit, restore evidence, audit, repair and safe mode.

## Data flow

`native owner fact → signed event gateway → minimized event → derivative model/build → quality/lineage → versioned snapshot → authorized dashboard/report/export → human/native-owner decision`

No event, snapshot, report, cache or UI projection is authorization or source of truth.
