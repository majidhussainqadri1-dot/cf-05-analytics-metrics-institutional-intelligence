# Implementation Status — CF-05 1.0.0-rc.1

## Status classes

| Status | State | Evidence boundary |
|---|---|---|
| Specified | Complete | Approved CF-05 master plan CF05-FR-001–035 |
| Coded | Complete candidate | Branch `codex/cf-05-complete-runtime-1.0.0-rc1` |
| Packaged | Pending | Deterministic WordPress ZIP/checksum/SBOM not yet produced |
| Automated QA | Pending final exact-head result | GitHub Actions matrix runs on PHP 8.1–8.3 |
| Staging Accepted | Pending | Hostinger workflows, integrations, browser/accessibility, migration and rollback |
| Live Deployed | No | No production change authorized |
| Operational | No | Providers, staffing, SLOs, backup/restore and incident evidence absent |

## Coding-complete domains

- governed event contracts, privacy gateway, replay resistance and quarantine;
- derivative datasets, lineage, quality rules and region/provider registry;
- idempotent pipeline/backfill/rebuild/provider-exit/restore job contracts;
- immutable semantic metrics and privacy-thresholded snapshots;
- access projects, scheduled reports and secure aggregate export requests;
- experiments, guardrails, statistical-plan metadata and human decision records;
- retention holds, expiry and deletion propagation;
- fail-closed runtime activation, health, audit and admin surfaces.

The runtime does not own source domain truth and cannot directly publish, rank, charge, prescribe, moderate or change user identity.
