# Sequential Review Round 32 — Narrative citation immutability and publication evidence

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (4)
1. Narrative citations can omit immutable snapshot hashes and remain metric/window-only.
2. Narrative citation windows accept loose timestamp syntax.
3. Narrative citation validation does not bind a supplied window to the cited snapshot or reject suppressed snapshot evidence.
4. Narrative publication does not revalidate cited evidence at publication time.

## Corrections
- Required immutable snapshot hashes, bound citation windows to snapshot rows and rejected suppressed evidence.
- Revalidated cited evidence immediately before publication.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
