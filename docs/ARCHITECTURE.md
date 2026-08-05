# CF-05 Architecture

## Governing law

CF-05 is a conditional analytical owner. It owns approved event-contract governance, privacy-safe derivative ingestion, semantic metric definitions, aggregate snapshots, analytics access projects, quality, lineage, reports and audit evidence. It is never the source of truth for identity, roles, posts, lessons, appointments, messages, clinical records, payments, rankings or human decisions.

## Layers

1. **Contract layer:** immutable event and metric versions.
2. **Privacy gateway:** allowlist-first fields, consent/purpose/minor checks, tokenization and quarantine.
3. **Derivative store:** short-retention minimized events and governed aggregate snapshots.
4. **Semantic layer:** version-pinned metrics with numerator, denominator, windows, dimensions and minimum cohort.
5. **Experience layer:** File 20 mounts routes; File 25 owns visual tokens; File 23 may present authorized summaries.
6. **Assurance layer:** native controls remain effective if File 24 is unavailable; File 24 receives evidence, not ownership.

## Runtime states

`foundation_disabled` → `catalog_only` → `staging_active` → `production_active`.

`safe_mode` is a fail-closed state. Ingestion requires explicit activation evidence plus secrets outside the repository.

## Data-flow invariant

Owner event → signed gateway → active contract → privacy transformation → dedupe/quarantine → minimized derivative event → model/aggregate job → version-pinned metric snapshot → authorized query/report.

No analytics result grants access or mutates a native domain object.
