# Sequential Review Round 81 — Catalog, quality, rebuild and metric-query authorization audit

The catalog mutation family, catalog lifecycle, quality controls, snapshots, backfills and metric-query service boundary were audited completely before any correction began. The defect ledger was frozen first.

## Frozen defect ledger (15)
1. Event-schema registration lacked service-layer `smai_manage_catalog`.
2. Dataset registration lacked service-layer `smai_manage_catalog`.
3. Metric registration lacked service-layer `smai_manage_catalog`.
4. Catalog lifecycle transition lacked service-layer `smai_approve_catalog`.
5. Quality-rule registration lacked service-layer `smai_manage_quality`.
6. Quality-rule activation lacked service-layer `smai_approve_catalog`.
7. Quality-run enqueue lacked service-layer `smai_manage_quality`.
8. Backfill planning lacked service-layer `smai_manage_backfills`.
9. Backfill dry-run lacked service-layer `smai_manage_backfills`.
10. Backfill approval/queue lacked service-layer `smai_approve_catalog`.
11. Backfill activation lacked service-layer `smai_approve_catalog`.
12. Backfill rollback lacked service-layer `smai_restore`.
13. Snapshot enqueue lacked service-layer `smai_manage_quality`.
14. Metric query relied on REST `smai_query_metrics` without service-layer enforcement.
15. Permanent authorization QA did not protect these catalog/quality/rebuild/query boundaries.

## Corrections after review completion
All listed user-triggered operations now enforce their required capability in the domain/service layer before mutation/query processing. Existing runtime-state, object-state, independent approval, access-project, privacy and evidence gates remain cumulative. Permanent authorization invariants now cover the corrected boundaries.

## Truth boundary
Queued internal workers continue to execute governed persisted jobs; this round hardens user-triggered service boundaries. Deployed roles, queues and live runtime state remain separate evidence.
