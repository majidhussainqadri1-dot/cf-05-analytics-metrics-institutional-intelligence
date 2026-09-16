# Security Policy

Report suspected CF-05 security or privacy defects privately to the platform owner. Never place secrets, personal data, exploit payloads, clinical records, payment data or private operational runbooks in public issues.

## Supported candidate

`1.0.0-rc.4` receives source-level corrections. Runtime remains conditional, disabled by default and schema/evidence/environment gated.

## Invariants

- secrets remain outside the repository and ordinary options;
- ingestion is HMAC-authenticated, timestamped, replay-resistant, allowlisted, sequence-bound and rate-limited;
- raw clinical, identity, message, credential, unrestricted-query and payment-secret content is prohibited;
- access, queries, reports and exports are purpose-bound, cohort-protected, revocable and audited;
- repeated slicing/differencing is budgeted and blocked;
- critical schema migration errors force Safe Mode;
- no claim of staging, certification, invulnerability or operational security follows from source/CI alone.
