# Security and Threat Model

## Principal threats

- unregistered or stale event contracts;
- forged, replayed or cross-service ingestion;
- secret, identity, payment, message or clinical data leakage;
- BOLA/IDOR through dashboard and metric dimensions;
- tiny-cohort and differencing re-identification;
- duplicate, late or reordered events causing false metrics;
- metric-version switching and denominator manipulation;
- CSV formula injection and long-lived exports;
- audit tampering, provider exit gaps and deleted identity resurrection.

## Foundation controls

- HMAC service authentication with five-minute window and replay cache;
- allowlisted service names through a site-level filter;
- immutable contract versions and payload hashes;
- allowlist-first field processing and separated pseudonymization key;
- quarantine with redacted samples only;
- unique event IDs and deterministic snapshot identity;
- allowlisted dimensions and minimum cohort enforcement;
- hash-chained minimized audit records;
- disabled-by-default runtime and fail-closed missing-key behavior;
- no secrets or real datasets in the repository.

Production acceptance additionally requires independent security/privacy review, provider/region approval, backup/restore, deletion propagation, penetration tests and incident exercises.
