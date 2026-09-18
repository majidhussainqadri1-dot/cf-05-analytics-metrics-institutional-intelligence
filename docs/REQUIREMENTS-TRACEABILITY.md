# CF-05 Requirements Traceability — 1.0.0-rc.10

| Requirement | Primary implementation | Acceptance evidence |
|---|---|---|
| CF05-FR-001 | `EventSchemaRegistry`, `EventSchemaValidator` | immutable semantic versions and closed schema |
| CF05-FR-002 | `EventIngestionService` | canonical envelope, owner/version/purpose checks |
| CF05-FR-003 | `ServiceAuthenticator`, replay nonce table | HMAC, timestamp and atomic replay rejection |
| CF05-FR-004 | `PrivacyGateway`, `SensitiveValueDetector` | allowlist minimization and prohibited-data rejection |
| CF05-FR-005 | event correction and sequence controls | correction owner validation, late/out-of-order evidence |
| CF05-FR-006 | quarantine workflow | redacted samples, reason codes and payload hashes |
| CF05-FR-007 | `DatasetCatalog`, `DatasetDefinitionValidator` | versioned derivative definitions and source contracts |
| CF05-FR-008 | `TransformationEngine`, `PipelineService` | strict typed deterministic transformations |
| CF05-FR-009 | `CheckpointService` | serialized watermarks and source sequence checkpoints |
| CF05-FR-010 | `LineageService` | source-to-dataset-to-metric lineage and code/job evidence |
| CF05-FR-011 | `QualityService` | freshness/completeness/uniqueness/validity/reconciliation rules |
| CF05-FR-012 | `BackfillService` | dry-run, shadow build, comparison and atomic activation |
| CF05-FR-013 | backfill rollback | previous-build pointer and governed rollback |
| CF05-FR-014 | `MetricCatalog`, `MetricDefinitionValidator` | immutable metric versions and semantic closure |
| CF05-FR-015 | `SnapshotService` | reproducible revisioned snapshots and supersession |
| CF05-FR-016 | quality/freshness response contract | normalized public quality states and caveats |
| CF05-FR-017 | historical semantics | current/as-occurred declaration in datasets and metrics |
| CF05-FR-018 | `AccessProjectService` | purpose, datasets/fields, training, expiry and approval |
| CF05-FR-019 | `DashboardService` | project/audience/metric reauthorization at query time |
| CF05-FR-020 | `MetricQueryService` | exact version/window/dimensions and audit |
| CF05-FR-021 | `PrivacyQueryPolicy`, `QueryPrivacyGuard` | dimension floors, cardinality, prohibited combinations, privacy budget and differencing defense |
| CF05-FR-022 | `ReportService`, `ReportControlService` | create/update/pause/resume/revoke/unsubscribe and delivery expiry |
| CF05-FR-023 | `ExportService`, `ExportControlService` | encrypted bounded export, safe CSV, explicit revoke and payload purge |
| CF05-FR-024 | `NarrativeService` | observation/inference/recommendation separation and snapshot citations |
| CF05-FR-025 | `ExperimentDefinitionValidator`, `ExperimentService` | hypothesis, audience, variants, metrics and guardrails |
| CF05-FR-026 | assignment facts | native assignment owner, one subject/experiment, deletion key and immutable fact hash |
| CF05-FR-027 | `Statistics`, experiment analysis | uncertainty, practical effect, protocol deviation and guardrail evidence |
| CF05-FR-028 | analysis publication | independent reviewer and immutable published analysis |
| CF05-FR-029 | decision records | human approver, alternatives, risks, owner and review outcome |
| CF05-FR-030 | `RetentionRunner` | bounded retention, expiry, token clearing and auditable batches |
| CF05-FR-031 | `DeletionService` | scoped deletion/anonymization and affected aggregate invalidation |
| CF05-FR-032 | `ProviderService` | evidence-bound provider approval, exit and credential revocation |
| CF05-FR-033 | query/output privacy | cohort suppression, repeated-query controls and minimized audit values |
| CF05-FR-034 | `RestoreService` | code/schema/catalog/checkpoint/deletion/access/provider invariants |
| CF05-FR-035 | `HealthService`, `RepairService`, CLI/REST/admin | fail-closed health, audit verification, safe repair and truthful status |
