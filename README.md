# CF-05 — Analytics, Metrics and Institutional Intelligence

CF-05 is the conditional analytical owner for the Sabri Social Homeopathy Platform. The `1.0.0-rc.3` source candidate implements `CF05-FR-001` through `CF05-FR-035` and the applicable harmonized controls of the Definitive Master Plan v3.0 and Consolidated All-Chats Directive Register 2.1, while remaining **disabled by default and fail-closed**.

## Implemented source domains

- immutable event schemas, signed replay-resistant ingestion, consent/purpose/minor gates, allowlist-first minimization, pseudonymization and redacted quarantine;
- derivative dataset registry, effective-dated models, event pipeline, checkpoints, quality rules, lineage, shadow backfills and atomic activation;
- version-pinned metric catalog, dimension-specific privacy policies, prohibited combinations, privacy budgets, differencing resistance, reproducible aggregate snapshots, minimum cohorts, freshness, uncertainty and caveats;
- purpose-limited access projects, dashboards, scheduled reports with pause/resume/update/revoke/unsubscribe controls, encrypted time-limited exports with explicit revocation, narrative insights and minimized audit evidence;
- governed experiments, assignment-fact separation, statistical integrity, guardrail evidence and human decision records;
- retention tiers, deletion/anonymization propagation, provider registry/exit evidence, restore points, repair, health, least-privilege roles and CLI operations.

## Safety boundary

CF-05 never becomes the source of truth for users, roles, posts, clinics, payments, messages, clinical records, recommendations or moderation. Raw clinical notes, prescriptions, private messages, identity evidence, credentials, unrestricted search queries and payment secrets are prohibited.

## Runtime activation

Installation does not activate ingestion or queries. Activation requires approved evidence matching `SMAI_ACTIVATION_EVIDENCE_SHA256`, private keys outside the repository, compatible owner contracts, Hostinger-equivalent staging and Founder acceptance.

## QA

```bash
composer qa
bash scripts/verify-deterministic-build.sh
```

The automated suite includes syntax, executable domain tests, privacy/differencing policy tests, JSON contracts, architecture/traceability, three-plan consistency, secret scanning and release identity checks on PHP 8.1–8.3.

## Truth status

Source coding and automated QA do not equal staging, live deployment or operational acceptance. Those later statuses require real integrations, browser/accessibility/load/security testing, backup/restore, rollback rehearsal and Founder approval.
