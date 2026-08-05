# CF-05 — Analytics, Metrics and Institutional Intelligence

CF-05 is the conditional analytical owner for the Sabri Social Homeopathy Platform. The `1.0.0-rc.2` source candidate implements the approved CF05-FR-001 through CF05-FR-035 scope while remaining **disabled by default and fail-closed**.

## Implemented source domains

- immutable event schemas, signed replay-resistant ingestion, consent/purpose/minor gates, allowlist-first minimization, pseudonymization and redacted quarantine;
- derivative dataset registry, effective-dated models, event pipeline, checkpoints, quality rules, lineage, shadow backfills and atomic activation;
- version-pinned metric catalog, reproducible aggregate snapshots, dimension allowlists, minimum cohorts, freshness, uncertainty and caveats;
- purpose-limited access projects, dashboards, scheduled reports, encrypted time-limited exports, narrative insights and full audit evidence;
- governed experiments, assignment-fact separation, statistical integrity, guardrail evidence and human decision records;
- retention tiers, deletion/anonymization propagation, provider registry/exit evidence, restore points, repair, health and CLI operations.

## Safety boundary

CF-05 never becomes the source of truth for users, roles, posts, clinics, payments, messages, clinical records, recommendations or moderation. Raw clinical notes, prescriptions, private messages, identity evidence, credentials, unrestricted search queries and payment secrets are prohibited.

## Runtime activation

Installation does not activate ingestion or queries. Activation requires:

1. approved evidence whose SHA-256 is stored and exactly matches `SMAI_ACTIVATION_EVIDENCE_SHA256`;
2. private pseudonymization, ingestion and export keys outside the repository;
3. an environment-compatible runtime state;
4. approved owner contracts and staging acceptance.

## Local QA

```bash
composer qa
```

The deterministic package builder is:

```bash
bash scripts/build-package.sh
```

## Truth status

Source coding can be complete while staging, live deployment and operational acceptance remain pending. This repository does not claim those later statuses.
