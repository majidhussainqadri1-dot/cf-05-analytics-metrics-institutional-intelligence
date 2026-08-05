# Security Policy

Report suspected CF-05 security or privacy defects privately to the platform owner. Do not place secrets, personal data, exploit payloads, clinical records, payment data or private operational runbooks in public GitHub issues.

## Supported source candidate

`1.0.0-rc.2` receives source-level security corrections. Runtime activation remains conditional and disabled by default.

## Security invariants

- private keys and secrets stay outside the repository and ordinary WordPress options;
- ingestion is HMAC-authenticated, timestamped, replay-resistant, allowlisted and rate-limited;
- raw C4/C5 clinical, identity, message, credential and payment content is prohibited;
- access, exports, reports and high-risk transitions are purpose-bound and audited;
- security evidence never authorizes native domain actions;
- no claim of being unhackable, certified or operationally secure is made without independent evidence.
