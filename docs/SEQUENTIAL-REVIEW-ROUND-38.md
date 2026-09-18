# Sequential Review Round 38 — Retention indexes, release identity and permanent regression gates

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (6)
1. Future run retention has no direct created_at index.
2. Time-based Future-40 retention tables lack direct updated_at indexes.
3. Closed incident/research retention lacks state+updated_at supporting indexes.
4. Substantial post-rc.6 corrections still identify the deployable candidate as rc.6.
5. Existing 1.4.0 installations would not rerun dbDelta for newly required retention indexes.
6. New sequential-review invariants are not yet a permanent QA gate.

## Corrections
- Added time/state indexes supporting Future-40 retention queries.
- Raised candidate to 1.0.0-rc.7 and schema to 1.4.1 while retaining contract family 1.4.0.
- Added permanent SR-30..39 invariant checks to full QA.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
