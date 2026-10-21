# Sequential Review Round 33 — Experiments, assignment time, metric drift and analysis privacy

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (6)
1. Experiment definition validator accepts loose start/end date syntax.
2. Experiment assignment facts accept loose occurred_at syntax.
3. Several experiment create/transition/assignment/analysis/publication transactions do not verify transaction start.
4. Experiment scheduling/running does not revalidate active metric and guardrail contracts after proposal creation.
5. Experiment analysis does not reapply current effective cohort/privacy policy to stored snapshots.
6. Decision review_at accepts loose timestamp syntax.

## Corrections
- Enforced strict RFC3339 experiment/assignment/decision timestamps.
- Made key experiment transactions check start/commit.
- Revalidated metric contracts at scheduling/running and privacy policy at analysis snapshot use.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
