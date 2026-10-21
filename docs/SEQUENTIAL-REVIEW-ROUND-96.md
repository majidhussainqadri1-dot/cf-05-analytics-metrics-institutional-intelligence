# Sequential Review Round 96 — Metric calculation and source-field semantic typing

Metric definition validation, published dataset binding, calculation/filter field references, runtime filter evaluation and snapshot numeric aggregation were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (3)
1. Metric registration verified that calculation fields existed but did not require `sum`/`average` targets to be numeric dataset fields.
2. Metric filters were not type-checked against the published dataset field contract; semantically impossible filters (for example a string value against a boolean field, or ordering operators against non-numeric fields) could be approved and silently produce misleading zero/empty results.
3. Snapshot numeric aggregation still accepted numeric-looking strings at runtime, allowing corrupt/legacy rows to bypass the now-governed numeric source contract.

## Corrections after review completion
- Metric registration now binds calculation/filter semantics to the exact published dataset field definitions.
- `sum` and `average` require integer/number sources.
- Ordering filters require numeric fields; filter values must match the source field type and string bounds.
- Snapshot numeric aggregation now accepts only finite integer/float values.
- Permanent metric invariants were extended so these semantic gates cannot silently regress.

## Truth boundary
This is repository metric-contract evidence only. It does not establish the live published dataset catalog, live metric definitions, existing snapshot rows or production query results.
