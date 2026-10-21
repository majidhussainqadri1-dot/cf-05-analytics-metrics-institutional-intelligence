# Sequential Review Round 94 — Service authentication and event-ingestion boundary

The complete service HMAC authentication, timestamp window, service allowlist, rate limit, replay nonce persistence, event-envelope validation, source/environment binding, schema lookup, consent/minor policy, privacy normalization, quarantine evidence, event persistence, pipeline enqueue and audit transaction were reviewed to completion before any correction decision.

## Frozen defect ledger (0)
No new source defect was confirmed in this boundary at the reviewed exact HEAD.

## Review conclusion
- HMAC material binds method, route, sanitized service identity, timestamp and body hash.
- Replay evidence is inserted only after signature verification and valid signed replays are rejected.
- Event ingestion fails closed when runtime/schema/private pseudonym configuration is unavailable.
- Consent/minor/privacy rules are enforced before persistence.
- Accepted event, pipeline job and audit evidence remain transactionally coupled.
- Rejected events preserve redacted quarantine evidence rather than raw payload storage.

## Truth boundary
This is repository-source review evidence only. It does not prove live service secrets, proxy/header behavior, deployed event producers, live nonce rows, live schema state or production ingestion behavior.
