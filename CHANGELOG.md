# Changelog

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
