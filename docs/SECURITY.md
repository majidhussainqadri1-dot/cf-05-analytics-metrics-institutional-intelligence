# Security Architecture

## Trust boundaries

Service ingestion uses HMAC over method, route, timestamp and body hash; service allowlisting, a five-minute window, atomic nonce storage, body limits and rate limiting apply. Browser mutations use WordPress capability checks, REST nonce/CSRF protection, exact object state/version and idempotency keys.

## Data protection

Export payloads use authenticated encryption, contextual binding, integrity hashes, requester binding, token hashing, TTL and revocation. Secrets remain private constants/secret-manager values, not repository content or ordinary options.

## Abuse resistance

- BOLA/IDOR: object/project/field/dimension authorization on every access;
- replay/duplication: event IDs, nonces, idempotency keys and state/version checks;
- injection: prepared SQL, fixed table/column registries, safe JSON, CSV formula neutralization and escaped UI;
- resource abuse: body, row, query, cohort, rate, queue and execution-time bounds;
- data leakage: no raw sensitive previews, no unrestricted query text, no public export URL, private/no-store responses;
- operational integrity: leased jobs, retries/dead-letter, audit hash chain, safe repair, safe mode and restore evidence.
