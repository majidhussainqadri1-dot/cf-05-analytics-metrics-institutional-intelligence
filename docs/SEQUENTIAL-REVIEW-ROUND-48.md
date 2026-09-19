# Sequential Review Round 48 — Main REST boundary, release identity and permanent regression gates

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (7)
1. Main REST payload parsing enforces the 1 MiB request limit only after JSON parsing, weakening the intended pre-parse resource bound.
2. Main REST payload accepts top-level JSON arrays despite declaring that a JSON object is required.
3. Dashboard GET wraps a possible WP_Error in an array-typed response path, causing an internal error instead of preserving the governed error response.
4. Runtime disable weakly coerces non-boolean foundation_disabled input.
5. Narrative creation weakly coerces non-boolean ai_assisted input.
6. Substantial post-rc.7 corrections still identify the deployable source candidate as rc.7.
7. The new sequential review invariants are not yet a permanent full-QA gate.

## Corrections
- Enforced pre-parse size/object JSON boundaries and strict high-risk booleans.
- Preserved Dashboard WP_Error responses.
- Raised source candidate to rc.8 and installed permanent SR-40..49 invariants.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
