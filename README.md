# CF-05 — Analytics, Metrics and Institutional Intelligence

CF-05 is the conditional derivative-analytics owner for the Sabri Social Homeopathy Platform. Candidate `1.0.0-rc.4` implements `CF05-FR-001` through `CF05-FR-035`, applicable Definitive Master Plan v3.0 controls and Consolidated All-Chats Directive Register 2.1 controls, while remaining disabled by default.

## Implemented domains

Immutable event contracts and signed ingestion; minimization/pseudonymization/quarantine; derivative datasets, transformations, checkpoints, lineage, quality and backfills; versioned metrics and revisioned snapshots; dimension-specific privacy, cohort floors, privacy budgets and differencing defense; purpose-limited access, dashboards, report lifecycle and encrypted revocable exports; governed experiments and human decisions; retention, deletion, provider exit, restore, health and least-privilege roles.

CF-05 never owns identity, native domain entities, clinical records, payments, messages, publication, moderation, search or recommendation decisions.

## Verification

```bash
composer qa
bash scripts/verify-deterministic-build.sh
```

Forty fresh review/fix rounds are recorded in `docs/REVIEW-ROUNDS-07-46.md`. Source/CI completion does not equal staging, live deployment or operation.
