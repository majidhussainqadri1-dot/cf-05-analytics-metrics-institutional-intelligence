## 1.0.0-rc.10 — 2026-09-18
- Post-cycle REST/CI security hardening: moved export/report bearer tokens out of query strings into dedicated headers, route-bound the raw CSV response filter to the exact CF-05 export endpoint, and advanced GitHub checkout/upload actions to current v7 majors to remove the deprecated Node.js 20 action-runtime path.
- Completed governed sequential review rounds SR-74..SR-83 using audit-first / fix-after-round discipline; hardened contract parity, release identity, Future-40/report/access/catalog/export/provider/deletion/restore service authorization and permanent regression gates.
- Corrected stale release-facing traceability and pull-request evidence so current candidate identity is not confused with older rc.8/rc.9 source states.
- Continued governed sequential review after SR-59, including repair authorization, REST typing, Future-40 reason minimization, restore consistency, scheduled-report atomicity, schema locking, retry idempotency and disclosure-contract closure.
- Corrected release-evidence drift after post-rc.9 source changes and made package review-round evidence derive from preserved sequential review records.
- Removed tracked Python cache artifacts and hardened repository hygiene against future cache leakage.
- Schema remains `1.4.1`; public contract family remains `1.4.0`. Staging/live/operational evidence remains separate.

## 1.0.0-rc.9 — 2026-09-18
- Closed sequential review rounds SR-50..SR-58 across audit-chain verification, operational repair provenance, atomic quality runs, snapshot concurrency, disclosure freshness, experiment/decision integrity, idempotency expiry and catalog-mode fail-closed controls.
- Advanced the current governing-source metadata to the CF-05 Conditional Complete Master Plan 2026 v1.1 Future40 Amended.
- Added a permanent SR-50..59 regression-invariant QA gate; schema remains `1.4.1` and public contract family remains `1.4.0`.
- Staging/live/operational evidence remains a separate lifecycle gate.

## 1.0.0-rc.8 — 2026-09-18
- Closed sequential review rounds SR-40..SR-49 across event contracts, jobs/pipeline, privacy accounting, exports, reports, deletion completeness, provider/restore governance and REST safety.
- Schema remains `1.4.1`; public contract family remains `1.4.0`.
- Staging/live/operational evidence remains a separate lifecycle gate.

## 1.0.0-rc.7 — 2026-09-18
- Closed sequential review rounds SR-30..SR-39 across access, dashboards, narrative evidence, experiments, audit/repair health, public contracts, Future-40 persistence, REST type safety and retention indexing.
- Raised database schema to `1.4.1`; public contract family remains `1.4.0`.
- Preserved staging/live/operational gates as independent evidence requirements.

# Changelog

## 1.0.0-rc.6 — 2026-09-16

- Final ten-round adversarial review hardened base runtime activation atomicity, governance REST idempotency/request parity and least-privilege uninstall cleanup.
- Hardened Future-40 API mutation idempotency, request limits, trace/error contracts, actor boundaries and global activation atomicity.
- Bound active Future runs to governed persistent artifacts and configuration/schema provenance.
- Added explicit Future derivative retention, bounded research-workspace expiry, and idempotent scheduled evidence with independent-approval rechecks.
- Removed retired one-shot mutation workflows/scripts and added a permanent repository-hygiene QA gate.
- Added governed CF-05 Future-40 expansion (`CF05-FUT-001..CF05-FUT-040`) with executable aggregate/advisory handlers.
- Added Future-40 lifecycle, independent approval, activation evidence, dry-run execution, incident evidence and scheduled internal evidence controls.
- Added Future-40 persistence, public feature contract, REST governance routes, privacy/re-identification safeguards and executable coverage for all 40 features.
- Raised database schema and contract family to `1.4.0`; Future-40 remains disabled by default and does not imply staging/live/operational acceptance.

## 1.0.0-rc.5 — 2026-09-16

- Closed remaining schema/write drift for event sequence identity, region/provider metadata and experiment deletion identity.
- Added fail-closed upgrade checks for duplicate historical sequence identities and legacy experiment facts that cannot satisfy privacy deletion.
- Made privacy-policy fixtures, three-plan consistency, and schema/write/release consistency mandatory QA gates.
- Made CI artifact integrity derive the candidate version from source rather than a stale hard-coded release.
- Raised database schema to `1.3.0`; public contract family remains `1.3.0`.

## 1.0.0-rc.4 — 2026-08-06

- Completed forty sequential fresh review-and-fix rounds (`REV-07..REV-46`).
- Added strict event metadata persistence for guardian consent, region and provider; source-sequence collision integrity.
- Added fail-closed schema migration verification, Safe Mode on critical drift and legacy experiment privacy-quality evidence.
- Added one-subject-per-experiment concurrency protection and assignment deletion keys.
- Hardened audit transactions/verifier, rate limits, idempotency, jobs, retention locks/batches and health degradation reasons.
- Hardened metrics, dimensions, privacy budgets, differencing, snapshots, datasets, pipelines, lineage, quality, backfills and access projects.
- Completed report lifecycle, explicit export revocation/payload purge, narrative citations, experiment/provider/deletion/restore controls.
- Corrected REST payload/idempotency/download headers, public error minimization, cache policy, accessible UI and least-privilege uninstall cleanup.
- Raised schema to `1.2.0`, contract family to `1.3.0` and expanded deterministic QA/release evidence.

## 1.0.0-rc.3 — 2026-08-06

- Added three-plan harmonization, dimension privacy, differencing resistance, report/export controls and least-privilege roles.

## 1.0.0-rc.2 — 2026-08-05

- Completed initial source implementation for `CF05-FR-001..CF05-FR-035`.
