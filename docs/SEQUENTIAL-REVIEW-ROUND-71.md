# Sequential Review Round 71 — Metric-query and disclosure privacy floors

This round followed the required discipline: the full audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 72 begins.

## Frozen defect ledger (6)
1. Dashboard disclosure calculated the effective minimum cohort from the global floor but omitted the metric's own declared `minimum_cohort`, allowing a dashboard slice to pass at a cohort size that a direct metric query would suppress.
2. Dashboard widget dimensions accepted non-finite floating-point values instead of failing the input contract closed.
3. Report creation accepted non-finite floating-point dimension values.
4. Report update validation had the same non-finite dimension gap.
5. Dashboard audience `capabilities` and `user_ids` used loose array coercion, so malformed scalar JSON could be normalized instead of rejected.
6. `MetricQueryService` relied on REST-layer dimension typing/count/sensitive screening and did not independently enforce those invariants at the domain boundary.

## Corrections
- Dashboard cohort suppression now uses `max(metric minimum, global minimum)` before applying dimension-specific privacy policy.
- Dashboard/report dimensions reject non-finite numeric values.
- Dashboard audience collections must be actual arrays.
- MetricQueryService independently enforces bounded, non-sensitive, scalar/null, finite dimension input.
- Permanent metric/privacy and reporting-governance QA gates now cover these controls.

## Truth boundary
Repository-source review and automated-QA evidence only. This does not prove deployed dashboard/report data, audience membership, live minimum-cohort configuration, or production privacy behavior.
