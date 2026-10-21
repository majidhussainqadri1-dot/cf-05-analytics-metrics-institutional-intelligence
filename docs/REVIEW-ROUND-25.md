# Review Round 25 — Service authentication and replay/rate-limit boundary

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. Service-auth HMAC does not bind the claimed service identity, allowing cross-service signature reuse when services share a secret.
2. The service rate-limit bucket is consumed before signature verification, allowing unauthenticated invalid signatures to exhaust an allowlisted service bucket.

## Corrections
- Bound service identity into the HMAC material.
- Moved service rate limiting after successful signature verification while retaining replay nonce protection.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
