# Sequential Review Round 87 — Metric differencing and dimension semantic boundary

Metric query authorization, access-project checks, dimension policy, privacy budget accounting, differencing logic, snapshot disclosure and REST dimension normalization were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (3)
1. Differencing protection compared only queries with identical time windows. A larger time window and a nested smaller window with the same dimensions could disclose a small edge cohort by subtraction without triggering the guard.
2. Dimension string values had no central length bound at the privacy-policy layer, so internal callers could bypass the transport layer's practical bound.
3. REST dimension strings were silently truncated to 100 characters, potentially changing the requested slice instead of rejecting a non-contract value.

## Corrections after review completion
- Added low-difference protection for nested time windows with identical dimension slices.
- Central privacy policy now rejects over-length string dimensions and non-finite numeric values.
- REST now rejects over-length or sanitizer-altered dimension values rather than silently changing them.
- Added regression tests and permanent metric/privacy invariants.

## Post-correction QA
The first correction commit exposed that `violations()` stopped validating a dimension value after detecting a missing dimension policy. That made the new bounded-value regression incomplete. The early `continue` was removed so policy absence and invalid value shape are both detected before this round was closed.

## Truth boundary
This is repository privacy-control evidence; it does not establish live query traffic, deployed privacy budgets or production access-project state.
