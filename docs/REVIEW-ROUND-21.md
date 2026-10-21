# Review Round 21 — Metric snapshots, queries and privacy semantics

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. Snapshot computation does not enforce the metric dimension privacy policy before publishing a value.
2. Snapshot suppression ignores dimension-specific minimum-cohort rules and uses only the global/base floor.
3. Snapshot publication commits before lineage and audit evidence, and those evidence-write failures are ignored.
4. Snapshot quality vocabulary (amber/red/stale) disagrees with query vocabulary (warning/degraded/unknown), degrading semantic consistency.
5. MetricQueryService requires a slug-like purpose while access projects store governed free-text purposes, making valid projects unusable for metric queries.
6. Snapshot job windows accept loose strtotime syntax instead of strict governed timestamps.

## Corrections
- Applied metric privacy policy and dimension-specific cohort floors during snapshot computation.
- Made snapshot publication, lineage, audit and commit one fail-closed transaction and normalized quality semantics.
- Aligned metric-query purpose handling with governed access-project free-text purposes.
- Made snapshot window parsing strict RFC3339.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
