# Sequential Review Round 72 — Experiment statistical integrity, privacy and decision evidence

This round followed the required discipline: the full experiment/analysis/decision audit was completed first and the defect ledger was frozen before any correction began. Full repository QA must pass before Round 73 begins.

## Frozen defect ledger (7)
1. Experiment snapshot disclosure used the global cohort floor but omitted the metric's own declared `minimum_cohort`.
2. Draft analysis persisted and returned exact per-variant assignment counts even when a variant was below the predeclared minimum sample.
3. The experiment contract permits 2–10 variants, but metric analysis compared only the first two variants and silently ignored additional treatment arms.
4. The predeclared `multiple_testing_policy` was recorded in the result but never applied to statistical conclusions.
5. Analysis publication rechecked metric contracts but did not revalidate the exact persisted snapshot hashes against current publication, privacy and cohort rules.
6. Decision records did not require the expected review date required by CF05-FR-029.
7. Decision-record input was not a closed contract and validation occurred before normalized action-owner/decision text, allowing unsupported fields or markup-only text to pass boundary checks.

## Corrections
- Experiment snapshot privacy now uses the maximum of metric and global cohort floors plus current dimension policy.
- Small per-variant assignment counts are withheld rather than disclosed exactly.
- Multi-arm analysis now compares every treatment variant against the predeclared first/control variant.
- Ratio comparisons expose deterministic two-sided p-values and the declared `single_primary`, `bonferroni`, `holm`, or `fdr` policy is applied before a conclusion is marked conclusive.
- Publication revalidates every referenced persisted snapshot hash against the current active metric, quality state, privacy policy and effective minimum cohort.
- Decision records now use a closed field set, normalized text validation and a required future review timestamp.
- Permanent experiment-governance QA invariants cover these controls.

## Governing-plan trace
CF05-FR-028 requires predeclared metrics/windows/exclusions, multiple-testing policy, missing-data handling, uncertainty and practical significance; CF05-FR-029 requires metric evidence, alternatives, risks, human approver, action/owner, expected review and linked outcome.

## Truth boundary
Repository-source review and automated-QA evidence only. No claim is made here about live assignment traffic, deployed experiment state, staging statistical fixtures, or production decision records.
