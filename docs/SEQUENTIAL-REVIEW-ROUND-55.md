# Sequential Review Round 55 — Experiment assignment, analysis publication and decision-record integrity

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 56 begins.

## Frozen defect ledger (5)
1. Repeated assignment for the same experiment subject and variant did not verify that the stored deletion key matched the retried governed fact.
2. The experiment `approved_by` field was overwritten again at scheduling, losing the original independent-review provenance.
3. Analysis publication did not re-check that all governed primary metrics and guardrails were still active and privacy-valid.
4. Decision records validated published analyses but allowed dangling experiment, metric and report subject references; actor identity was not validated at the service boundary.
5. Decision-outcome and analysis-publication service methods lacked complete actor/version/UUID boundary validation.

## Corrections
- Same-subject assignment retries now require both variant and deletion-key consistency.
- Preserved the independent reviewer in `approved_by`; later scheduling no longer overwrites that provenance.
- Revalidate metric/guardrail contracts immediately before publishing an experiment analysis.
- Added governed subject-existence checks for experiment, analysis, metric and report decisions.
- Added actor, row-version and UUID validation to publication/outcome service boundaries.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
