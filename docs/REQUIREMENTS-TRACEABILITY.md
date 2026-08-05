# CF-05 Requirements Traceability Matrix — 1.0.0-rc.3

| Requirement | Principal implementation/evidence |
|---|---|
| CF05-FR-001 Event schema registry | `EventSchemaRegistry`, `EventSchemaValidator`, immutable hash/version lifecycle |
| CF05-FR-002 Minimum event envelope | `EventIngestionService`, `contracts/event-envelope.schema.json` |
| CF05-FR-003 Purpose/consent gate | `PrivacyGateway`, ingestion purpose/consent/minor/region rejection paths |
| CF05-FR-004 Redaction/tokenization | `PrivacyGateway`, `SensitiveValueDetector`, contextual HMAC pseudonyms |
| CF05-FR-005 Dedupe/order/late events | event UUID/sequence constraints, checkpoint/watermark and correction fields |
| CF05-FR-006 Quarantine and feedback | redacted quarantine, reason/retry metadata, no unsafe pipeline enqueue |
| CF05-FR-007 Canonical derivative modeling | `DatasetCatalog`, `TransformationEngine`, `PipelineService` |
| CF05-FR-008 Slowly changing definitions | effective date/current-row handling in `PipelineService` |
| CF05-FR-009 Data lineage | `LineageService`, event/build/snapshot/metric edges and hashes |
| CF05-FR-010 Backfill/rebuild | `BackfillService`, dry-run/shadow/compare/activate/rollback with previous-build pointer |
| CF05-FR-011 Data quality rules | `QualityService`, governed rules/results/issues |
| CF05-FR-012 Region/provider control | dataset validation, `ProviderService`, region/provider activation gates |
| CF05-FR-013 Metric definition | `MetricCatalog`, `MetricDefinitionValidator`, immutable source/calculation/privacy definition |
| CF05-FR-014 Metric lifecycle | `LifecyclePolicy`, `CatalogLifecycleService`, independent approval and row versions |
| CF05-FR-015 Dimension allowlist | dimension policies: sensitivity, cardinality, minimum cohort and prohibited combinations |
| CF05-FR-016 Freshness/confidence | `SnapshotService`, `MetricQueryService`, data-through, quality, coverage, interval and caveats |
| CF05-FR-017 Attribution/funnels | versioned calculation/filter contracts, explicit windows and source version |
| CF05-FR-018 Financial/clinical limits | event/field exclusions; only approved aggregates, never raw ledgers/charts or individual verdicts |
| CF05-FR-019 Role/project dashboards | `AccessProjectService`, `DashboardService`, REST/admin/shortcode and no-store output |
| CF05-FR-020 Metric presentation | exact version/window/freshness/caveats/source owner in query/dashboard responses |
| CF05-FR-021 Drilldown safety | `PrivacyQueryPolicy`, `QueryPrivacyGuard`, cohort suppression, slice/privacy budgets and differencing defense |
| CF05-FR-022 Scheduled reports | `ReportService`, `ReportControlService`, create/approve/update/pause/resume/revoke/unsubscribe/delivery expiry |
| CF05-FR-023 Secure exports | `ExportService`, `ExportControlService`, allowlisted aggregates, CSV neutralization, encryption, TTL, hash and revoke/purge |
| CF05-FR-024 Narrative insights | `NarrativeService`, observation/inference/recommendation separation, citations and reviewer |
| CF05-FR-025 Experiment registry | `ExperimentService`, experiment validator and governed definitions |
| CF05-FR-026 Assignment separation | signed assignment-fact endpoint; assignment owner retained, no mutation authority |
| CF05-FR-027 Guardrail monitoring | experiment analysis and evidence, no silent native-domain writes |
| CF05-FR-028 Statistical integrity | `Statistics`, predeclared design, uncertainty, effect and inconclusive/deviation output |
| CF05-FR-029 Decision record | decision/outcome records with evidence, alternatives, risks, approver and review date |
| CF05-FR-030 Retention tiers | `RetentionRunner`, per-contract/dataset/export/delivery/job expiry |
| CF05-FR-031 Deletion propagation | `DeletionService`, snapshots/reports/exports/provider reconciliation and zero-eligible verification |
| CF05-FR-032 Access projects/expiry | purpose/dataset/field/training/approval/expiry/revoke with child revocation |
| CF05-FR-033 Query/export audit | minimized project/purpose/dimension/result/status evidence plus privacy-block audit |
| CF05-FR-034 Provider/warehouse exit | `ProviderService`, inventory/dependencies/parity/purge/credential-revocation evidence |
| CF05-FR-035 Restore/reproducibility | `RestoreService`, code/schema/catalog/checkpoint/deletion/access floors and post-restore verification |

Three-plan applicability is traced separately in `docs/THREE-PLAN-TRACEABILITY.md`.
