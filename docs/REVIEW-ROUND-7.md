# CF-05 Review Round 7 — Privacy, Retention and Research Expiry

Audit completed before corrections. Defect ledger was frozen before code changes.

## Defects found
1. Future run evidence, scenario models and intelligence alerts had no explicit retention cleanup.
2. Resolved Future analytics incidents had no retention cleanup.
3. Research workspaces were persisted with `expires_at = NULL`, contradicting governed expiry.
4. FUT-029 accepted research workspace metadata without an expiry field and QA did not guard these rules.

## Corrections
- Added bounded Future-40 retention options and cleanup paths.
- Expired research workspaces are state-marked, then purged after the modeled-retention window.
- FUT-029 now requires `expires_on`; active persistence requires a future UTC date no more than 365 days ahead.
- Added automated invariants and regression coverage.

Status boundary: repository/source correction only; no staging/live claim.
