# CF-05 Requirements Traceability Matrix

| Requirement | Source implementation | Principal evidence |
|---|---|---|
| CF05-FR-001 Event schema registry | `EventSchemaRegistry`, `EventSchemaValidator`, lifecycle | immutable contract/hash and unknown-version quarantine tests |
| CF05-FR-002 Minimum event envelope | `EventIngestionService`, event JSON Schema | required envelope validation and safe column schema |
| CF05-FR-003 Purpose/consent gate | `PrivacyGateway`, `EventIngestionService` | consent/minor/purpose/region rejection paths |
| CF05-FR-004 Redaction/tokenization | `PrivacyGateway`, `SensitiveValueDetector` | allowlist, HMAC pseudonyms, sensitive corpus rejection |
| CF05-FR-005 Dedupe/order/late events | `EventIngestionService`, `CheckpointService` | event uniqueness, sequence/watermark, late/correction columns |
| CF05-FR-006 Quarantine and feedback | `EventIngestionService::quarantine`, admin quality surface | redacted samples, bounded retry metadata, no pipeline enqueue |
| CF05-FR-007 Canonical derivative modeling | `DatasetCatalog`, `TransformationEngine`, `PipelineService` | owner IDs/versions, declarative field mapping, active builds |
| CF05-FR-008 Slowly changing definitions | `PipelineService`, effective date/current row columns | close previous row and append effective-dated successor |
| CF05-FR-009 Data lineage | `LineageService`, `lineage_edges` | event→build→snapshot and metric→snapshot edges with SHA/job/owner |
| CF05-FR-010 Backfill/rebuild | `BackfillService`, job queue, build pointer | plan/dry-run/shadow/compare/approve/activate/rollback-safe model |
| CF05-FR-011 Data quality rules | `QualityService`, quality tables | freshness/completeness/uniqueness/validity/referential/drift/reconciliation results |
| CF05-FR-012 Region/provider control | `DatasetDefinitionValidator`, `ProviderService`, catalog activation gates | allowed region, active provider, exit evidence |
| CF05-FR-013 Metric definition | `MetricCatalog`, `MetricDefinitionValidator` | immutable ID/version/source/calculation/dimension/cohort definition |
| CF05-FR-014 Metric lifecycle | `LifecyclePolicy`, `CatalogLifecycleService` | draft→validated→approved→active→deprecated/invalidated→retired |
| CF05-FR-015 Dimension allowlist | `MetricQueryService`, `SnapshotService` | exact approved dimensions and cohort suppression |
| CF05-FR-016 Freshness/confidence | `SnapshotService`, `MetricQueryService` | data-through, quality, coverage, Wilson interval, caveats |
| CF05-FR-017 Attribution/funnels | metric calculation/filter contracts and dedupe pipeline | explicit windows, filters, source version and retry exclusion |
| CF05-FR-018 Financial/clinical limits | event/field exclusions and owner contracts | only approved aggregates; no raw ledger/chart or individual verdict |
| CF05-FR-019 Role/project dashboards | `AccessProjectService`, `DashboardService`, REST/admin/shortcode | project purpose/expiry, widget metric versions and no-store output |
| CF05-FR-020 Metric presentation | `MetricQueryService`, `DashboardService`, `InsightsShortcode` | definition/version/window/freshness/caveat/source owner |
| CF05-FR-021 Drilldown safety | query allowlist, row/window/rate/cohort bounds | no arbitrary free text/user field; suppressed tiny cohorts |
| CF05-FR-022 Scheduled reports | `ReportService`, report/delivery tables, cron | approved project, exact bundle, recipient hash, expiring secure link |
| CF05-FR-023 Secure exports | `ExportService`, `CryptoBox`, `CsvSafe` | approved aggregate columns, row cap, AES-GCM/secretbox, TTL/hash/audit |
| CF05-FR-024 Narrative insights | `NarrativeService` | observation/inference/recommendation separation, citations and reviewer |
| CF05-FR-025 Experiment registry | `ExperimentService`, validator, experiment tables | hypothesis/audience/owner/metrics/guardrails/design/privacy/review |
| CF05-FR-026 Assignment separation | signed service assignment endpoint and `experiment_facts` | facts only; external owner retained; no assignment mutation API |
| CF05-FR-027 Guardrail monitoring | experiment analysis and decision evidence | breached guardrail reported; no silent native write |
| CF05-FR-028 Statistical integrity | `Statistics`, `ExperimentService::analyze` | predeclared design, uncertainty, effect, deviations/inconclusive output |
| CF05-FR-029 Decision record | `ExperimentService::recordDecision`, `decision_records` | evidence/alternatives/risks/approver/action/review/outcome linkage |
| CF05-FR-030 Retention tiers | `RetentionRunner`, per-contract/dataset/options | short raw/quarantine, bounded model, export/delivery/job expiry |
| CF05-FR-031 Deletion propagation | `DeletionService`, reconciliation table | event/rows/experiment/export/report/snapshot/provider handling and zero-eligible check |
| CF05-FR-032 Access projects/expiry | `AccessProjectService`, expiry cron | purpose/datasets/fields/training/approver/expiry/revoke and child revocation |
| CF05-FR-033 Query/export audit | `AuditLogger`, query/export/report services | minimized actor/project/purpose/dimensions/result-size/status/trace evidence |
| CF05-FR-034 Provider/warehouse exit | `ProviderService`, lineage/catalog/export/status | inventory, active dependencies, parity/exit/purge/credential-revoke evidence |
| CF05-FR-035 Restore/reproducibility | `RestoreService`, restore points, checkpoints/catalog hashes | code/schema/catalog/checkpoint/deletion/access floors and post-restore verification |
