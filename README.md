# CF-05 — Analytics, Metrics and Institutional Intelligence

CF-05 is the conditional derivative-analytics owner for the Sabri Social Homeopathy Platform. Candidate `1.0.0-rc.19` implements `CF05-FR-001` through `CF05-FR-035`, applicable Definitive Master Plan v3.0 controls and Consolidated All-Chats Directive Register 2.1 controls and the CF-05 Conditional Complete Master Plan 2026 v1.1 Future40 Amended controls, while remaining disabled by default.

## Implemented domains

Immutable event contracts and signed ingestion; minimization/pseudonymization/quarantine; derivative datasets, transformations, checkpoints, lineage, quality and backfills; versioned metrics and revisioned snapshots; dimension-specific privacy, cohort floors, privacy budgets and differencing defense; purpose-limited access, dashboards, report lifecycle and encrypted revocable exports; governed experiments and human decisions; retention, deletion, provider exit, restore, health and least-privilege roles.

CF-05 never owns identity, native domain entities, clinical records, payments, messages, publication, moderation, search or recommendation decisions.

## Verification

```bash
composer qa
bash scripts/verify-deterministic-build.sh
```

Governed review/fix evidence is preserved under `docs/`, including the historical `REVIEW-ROUNDS-07-46.md` ledger and all later sequential round records. Source/CI completion does not equal staging, live deployment or operation.

## Future-40 expansion

`CF05-FUT-001..CF05-FUT-040` are source-coded behind independent approval and exact activation-evidence gates. They remain aggregate-only, advisory, human-governed and disabled by default. See `docs/FUTURE-40.md`.

## V12.3 native contracts and acceptance

File 23's real SPDB_Provider_Adapter/SPDB_Analytics_Provider interface is now source-coded as an institution-only, project-authorized, aggregate-only bridge. File 24 receives an unassessed native module manifest, not fabricated privacy deletion ownership. The 35 FR, 40 FUT, 6 journey, 12 Traffic and 14 Disease acceptance items are tracked in contracts/cf05-v12.3-acceptance-matrix.json. scripts/v123-acceptance-gate.py --release-ready fails closed while source-owner or live evidence is absent.
