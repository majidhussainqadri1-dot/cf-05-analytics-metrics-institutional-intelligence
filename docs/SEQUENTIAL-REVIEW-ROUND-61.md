# Sequential Review Round 61 — REST fail-closed query parsing and governance input typing

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 62 begins.

## Frozen defect ledger (4)
1. Malformed JSON supplied as metric dimensions could decode to null and silently become an empty-dimension query, broadening the requested slice instead of failing closed.
2. More than ten dimensions, invalid keys and unsupported values were silently truncated or discarded rather than rejected.
3. Access-project `training_confirmed` was cast to boolean at the REST boundary, so non-boolean JSON values such as the string `"false"` could be interpreted as true.
4. Main and governance REST mutations loosely cast `row_version` values to integers, allowing malformed JSON strings to become valid optimistic-concurrency versions.

## Corrections
- Metric dimensions now require an object-shaped input and reject malformed JSON, lists, excess dimensions, invalid keys, non-scalar values and non-finite floats.
- Access-project training confirmation now requires an explicit JSON boolean.
- Main and governance mutation payloads now require any supplied `row_version` to be a positive JSON integer.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
