# Sequential Review Round 98 — Dashboard identity and presentation-contract fidelity

Dashboard registration, activation, audience/access binding, widget privacy checks, latest-snapshot disclosure and front-end shortcode rendering were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (2)
1. The shortcode applied `sanitize_key()` to dashboard IDs even though the governed dashboard contract permits dots. A valid ID such as `education.progress` was therefore changed before lookup and could not be rendered.
2. Dashboard names were neither required to be strings nor bounded to the database/display limit before the immutable definition hash was computed. The stored display name was later stripped/truncated, allowing accepted canonical evidence to diverge from the value actually persisted/displayed.

## Corrections after review completion
- Shortcode dashboard identity now uses the exact dashboard-ID and semantic-version contract without lossy WordPress key sanitization.
- Invalid shortcode identities fail closed with a governed unavailable state.
- Dashboard names must be strings, 3..190 bytes, already tag-free/non-lossy, and the exact accepted trimmed value is bound into the canonical definition.
- Reporting-governance invariants now protect both identity and canonical-display parity.

## Truth boundary
This is repository dashboard/presentation evidence only. It does not establish live shortcode content, WordPress page configuration, active dashboard rows, access-project state or production disclosure behavior.
