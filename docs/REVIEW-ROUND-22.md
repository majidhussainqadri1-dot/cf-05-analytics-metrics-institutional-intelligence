# Review Round 22 — Exports, reports and disclosure privacy

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. Export row filtering applies a single empty-dimension cohort floor and does not re-evaluate each exported slice against dimension-specific privacy policy.
2. Export window parsing accepts incomplete/loose timestamps.
3. Report creation accepts metric dimension slices without validating metric allowlists or privacy policy.
4. Report updates accept changed metric slices without validating metric allowlists or privacy policy.
5. Report generation trusts stored suppression state and does not re-evaluate the current effective cohort/privacy policy before disclosure.

## Corrections
- Re-evaluated each export row against its actual dimensions and current effective cohort floor.
- Made export timestamps strict RFC3339.
- Added metric dimension allowlist/privacy validation to report creation and updates.
- Re-evaluated report snapshot privacy and cohort thresholds at read time.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
