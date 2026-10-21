# Sequential Review Round 42 — Metric-query privacy accounting and exact window validation

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (2)
1. Privacy accounting treats the same dimensions across different time windows as an exact repeated slice, allowing new windows to bypass distinct-slice/budget accounting.
2. Metric query window validation can normalize impossible calendar dates rather than reject them exactly.

## Corrections
- Accounted exact repeats by full time-window slice, not dimensions alone.
- Made metric query windows calendar-exact.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
