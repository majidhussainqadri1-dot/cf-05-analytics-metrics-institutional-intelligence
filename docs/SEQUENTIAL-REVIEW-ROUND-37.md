# Sequential Review Round 37 — Future/Governance REST type and JSON-object safety

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (4)
1. Future run coerces non-boolean dry_run values instead of enforcing JSON boolean.
2. Future lifecycle endpoints coerce malformed row_version values instead of rejecting invalid JSON types.
3. Future mutation payload accepts top-level JSON arrays although API requires an object.
4. Governance mutation payload also accepts top-level JSON arrays.

## Corrections
- Made dry_run a strict JSON boolean and row_version a strict non-negative JSON integer.
- Rejected top-level JSON arrays on Future/Governance mutation endpoints.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
