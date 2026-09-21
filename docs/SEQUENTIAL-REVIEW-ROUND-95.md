# Sequential Review Round 95 — Dataset projection type and required-field fidelity

Dataset definition validation, active source-contract binding, envelope/property mappings, transformation normalization and pipeline row persistence were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (4)
1. Safe envelope mappings were allowlisted by name but not checked for compatible target types, allowing definitions such as a timestamp mapped into an integer field.
2. A dataset field marked required could map to an optional source property or optional envelope reference, allowing published rows to contain null despite the required contract.
3. Enum-to-string projection accepted typed integer/boolean enum members and could collapse distinct values such as `true`, `1` and `"1"` into the same string representation.
4. Transformation silently coerced numeric strings and ignored target string-length/required semantics, so a persisted dataset row could diverge from its governed definition.

## Corrections after review completion
- Added explicit type/required metadata for every allowed envelope mapping.
- Required dataset fields must now bind only to required sources across all contributing event contracts.
- Enum-to-string projection is accepted only for string-only enums and target string capacity must cover the source contract.
- Transformation now uses strict typed normalization, enforces target string bounds, handles persisted 0/1 event booleans, and fails closed on lossy normalization or missing required fields.
- Added executable regression coverage for strict projection behavior.

## Truth boundary
This is repository projection-contract evidence only. It does not establish live event contracts, published dataset definitions, existing projected rows, backfill status or deployed data quality.
