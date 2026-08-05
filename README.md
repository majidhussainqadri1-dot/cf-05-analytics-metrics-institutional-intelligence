# CF-05 — Analytics, Metrics and Institutional Intelligence

Conditional WordPress foundation for privacy-safe analytics, governed event ingestion, semantic metric definitions and institutional decision-support within the Sabri Social Homeopathy Platform.

## Governing boundary

CF-05 owns derivative analytics governance after approved activation. It does **not** own identity, domain records, clinical charts, private messages, payment ledgers, search/recommendation ranking or human decisions.

## Current release truth

| Gate | Status |
|---|---|
| Specified | Complete plan supplied |
| Foundation source | `0.1.0` candidate |
| Schema | `0.2.0` |
| Local QA | Green within declared source scope |
| Deterministic package | Pending |
| Exact GitHub-head CI | Pending |
| Staging accepted | No |
| Live deployed | No |
| Operational | No |

Runtime event ingestion and metric queries are disabled by default. Activation requires an approved evidence hash configured both in the database and `wp-config.php`, an environment-compatible runtime state, secrets outside the repository and the external governance/staging gates defined by the plan.

## Implemented foundation

- Immutable versioned event-schema registry.
- Governed event and metric lifecycle transitions with optimistic concurrency and separation of duties.
- HMAC-authenticated, replay-resistant service ingestion.
- Purpose/consent/minor policy checks, strict field allowlists, pseudonymization and sensitive-value rejection.
- Idempotent event storage and redacted quarantine.
- Version-pinned metric catalog/query contracts, allowlisted dimensions and minimum-cohort suppression.
- Concurrency-safe hash-chained audit evidence.
- Health, retention and accessible WordPress administration surfaces.
- Public-safe architecture, security, privacy, migration, rollback and staging documentation.

## Required private configuration

Never commit these values:

```php
define('SMAI_INGESTION_SECRET', 'at-least-32-random-bytes');
define('SMAI_PSEUDONYM_KEY', 'at-least-32-random-bytes');
define('SMAI_ACTIVATION_EVIDENCE_SHA256', '64-character-approved-evidence-hash');
```

The matching `smai_activation_evidence_hash` and explicit approval state must be recorded through a controlled operational process. Repository code alone cannot authorize live analytics.

## Development QA

```bash
bash scripts/qa.sh
```

See `docs/IMPLEMENTATION-STATUS.md`, `docs/REVIEW-ROUND-1.md` and `docs/REVIEW-ROUND-2.md` for scope and findings.
