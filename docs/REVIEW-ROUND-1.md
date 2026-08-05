# Review Round 1 — Corrective Review

## Findings corrected

1. Event ingestion originally depended on timestamp/signature reuse checks without an atomic replay ledger. A dedicated nonce table and `INSERT IGNORE` replay gate were added.
2. Text handling assumed `mbstring`; bounded fallback-safe text handling was introduced.
3. Contract and payload controls initially blocked dangerous field names but did not inspect dangerous values inside allowed strings. A bounded sensitive-value detector now rejects credentials, keys, tokens, email/IP/phone-like identifiers and valid payment-card values.
4. Catalog records could be registered but had no governed path to an active state. Explicit event-schema and metric lifecycle policies, immutable transition evidence, optimistic row versions and independent approval gates were added.
5. Audit hash chaining selected the previous record without serialization. A transactional audit-head record now prevents concurrent chain forks.

## Verification

- PHP syntax check across all source and test files.
- Contract/privacy/lifecycle unit fixtures.
- JSON validation for manifest, contracts and Composer metadata.
- Repository secret-pattern scan.

All discovered Round-1 source defects were corrected before the fresh review.
