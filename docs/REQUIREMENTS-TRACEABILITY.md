# CF-05 Requirements Traceability — 1.0.0-rc.13

**Canonical specification:** CF-05 Conditional Complete Master Plan 2026 v1.1 (Future-40 amended 2026-09-16), Section 5. Earlier repository mapping assigned several CF05-FR IDs to different subjects; those labels were not the governing plan. This table restores the original requirement identities. A referenced PHP class establishes source intent, **not** staging, live, or cross-file operational acceptance.

| Governing ID | Exact plan subject | Primary code linkage | Evidence / remaining gate |
|---|---|---|---|
| CF05-FR-001 | Event schema registry | `EventSchemaRegistry`, `EventSchemaValidator` | Schema-version and owner/privacy gate |
| CF05-FR-002 | Minimum event envelope | `EventSchemaValidator`, `EventIngestionService` | Required identity/time/source/consent fields |
| CF05-FR-003 | Purpose/consent gate | `EventIngestionService`, `PrivacyGateway` | Consent, minor/guardian, purpose and region checks |
| CF05-FR-004 | Field redaction/tokenization | `PrivacyGateway`, `SensitiveValueDetector` | Allowlist, secret rejection, pseudonymization |
| CF05-FR-005 | Dedupe/order/late events | `EventIngestionService`, `CheckpointService` | Replay, source order, late-window and correction evidence |
| CF05-FR-006 | Quarantine and feedback | `EventIngestionService`, `QualityService` | Safe quarantine and governed replay |
| CF05-FR-007 | Canonical derivative modeling | `DatasetCatalog`, `DatasetDefinitionValidator`, `PipelineService` | Published source ownership and derivative-only model |
| CF05-FR-008 | Slowly changing definitions | `DatasetDefinitionValidator`, `SnapshotService`, `PipelineService` | Current/as-occurred historical policy |
| CF05-FR-009 | Data lineage | `LineageService`, `PipelineService` | Event/build/metric linkage and provenance |
| CF05-FR-010 | Backfill/rebuild | `BackfillService`, `CheckpointService` | Dry-run, shadow-build, reconcile and rollback |
| CF05-FR-011 | Data quality rules | `QualityService`, `SnapshotService` | Freshness, violations and suppression |
| CF05-FR-012 | Region/provider control | `DatasetCatalog`, `EventIngestionService`, `ProviderService` | Approved region/provider contract; staging provider evidence pending |
| CF05-FR-013 | Metric definition | `MetricDefinitionValidator`, `MetricCatalog` | Numerator/denominator/window/timezone/version validation |
| CF05-FR-014 | Metric lifecycle | `CatalogLifecycleService`, `MetricCatalog` | Versioned transition and invalidation |
| CF05-FR-015 | Dimension allowlist | `MetricDefinitionValidator`, `MetricQueryService` | Cardinality/privacy/allowlisted filters |
| CF05-FR-016 | Freshness and confidence | `MetricQueryService`, `SnapshotService` | Freshness/caveats/unknown-vs-green |
| CF05-FR-017 | Attribution and funnels | `FunnelAttributionCalculator`, `MetricDefinitionValidator`, `MetricCatalog`, `SnapshotService` | Bounded subject-deduplicated first/last/linear fixtures; source/staging integration pending |
| CF05-FR-018 | Financial/clinical metric limits | `PrivacyGateway`, `SensitiveValueDetector`, `MetricCatalog` | No raw clinical/payment secret data; native CF-03/clinical aggregate contract pending |
| CF05-FR-019 | Role/project-scoped dashboards | `DashboardService`, `AccessProjectService` | Purpose-scoped authorization and expiry |
| CF05-FR-020 | Metric presentation | `InsightsShortcode`, `DashboardService`, `MetricQueryService` | Version/quality/caveats/accessible disclosure |
| CF05-FR-021 | Drilldown safety | `PrivacyQueryPolicy`, `QueryPrivacyGuard` | Cohort thresholds, differencing and budgets |
| CF05-FR-022 | Scheduled reports | `ReportService`, `ReportControlService` | Recipient authorization, expiry and revocation |
| CF05-FR-023 | Secure exports | `ExportService`, `ExportControlService`, `CryptoBox`, `CsvSafe` | Bounded encrypted export and revoke |
| CF05-FR-024 | Narrative insights | `NarrativeService` | Evidence citations, inference/recommendation distinction |
| CF05-FR-025 | Experiment registry | `ExperimentService`, `ExperimentDefinitionValidator` | Hypothesis/metrics/guardrails/approval |
| CF05-FR-026 | Assignment separation | `ExperimentService`, `IntegrationRegistry` | Native owner remains assignment authority |
| CF05-FR-027 | Guardrail monitoring | `ExperimentService` | Analysis and breach/stop rules |
| CF05-FR-028 | Statistical integrity | `Statistics`, `ExperimentService` | Confidence and guarded comparisons |
| CF05-FR-029 | Decision record | `ExperimentService`, `NarrativeService` | Human approval and decision audit |
| CF05-FR-030 | Retention tiers | `RetentionRunner`, `Activator` | Raw/derivative expiration and audit |
| CF05-FR-031 | Deletion/anonymization propagation | `DeletionService`, `RetentionRunner` | Delete/revoke derived reports and exports |
| CF05-FR-032 | Access projects and expiry | `AccessProjectService` | Object/field/purpose authorization and expiry |
| CF05-FR-033 | Query and export audit | `MetricQueryService`, `AuditLogger`, `ExportService` | Minimized query/export trace and result-size |
| CF05-FR-034 | Provider/warehouse exit | `ProviderService`, `RestoreService` | Provider handoff, purge and reconciliation evidence |
| CF05-FR-035 | Restore and reproducibility | `RestoreService`, `AuditVerifier` | Schema/catalog/deletion-floor/access parity check |

## Independent acceptance boundaries

- **CF05-FR-017:** The prior candidate silently accepted a freeform `attribution` field while providing only row-level count/sum/average/ratio, not a subject-deduplicated ordered funnel. The review adds a bounded `funnel` calculation with time windows, explicitly eligible source events, pseudonymous subjects, first/last/linear channel attribution and fail-closed validation. Source fixtures do **not** substitute for genuine File 00/05/26 producer contracts and staging cohort verification.
- **CF05-FR-018:** Rejecting raw clinical/payment material is necessary but not sufficient evidence that the separate clinical and CF-03 financial domain owners emit safe, consented aggregate contracts. Those external integrations are pending.
- **CF05-FR-012, -026 and -034:** Provider/region, assignment authority and provider exit require real companion services and staging acceptance; repository markers alone do not satisfy end-to-end integration.
- **CF05-FR-003 and -031:** Consent, minor safety, right-to-delete and downstream correction must be tested on deployment data and actual integrations.
- The **public Traffic Analytics & Transparency** plan retains its own first-party traffic definition and public privacy methodology. CF-05 may consume approved aggregate/event contracts; it must not silently become a second canonical visitor counter or the File 26 ranking authority.
- Future-40 `CF05-FUT-001..040` remains separately disabled and activation-gated. This table does not reassign or erase that scope.

## Lifecycle truth

Repository code/CI/package evidence must be tied to the exact new branch HEAD. Live deployed source, DB version, migrations, provider contracts, Hostinger-equivalent staging, production verification and Founder release approval are **not established** by this document. Unknown means unverified—not zero defects.
