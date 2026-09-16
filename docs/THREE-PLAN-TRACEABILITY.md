# CF-05 Three-Plan Traceability — 1.0.0-rc.4

## Governing sources

1. **Sabri Social Homeopathy Platform Definitive Integrated Master Plan 2026 v3.0** — canonical ownership, evidence-only lifecycle status, staging-first deployment, cross-file integration, security/privacy, accessibility, migration and rollback.
2. **Consolidated All-Chats Recovered Directive Register 2.1** — Islamic supremacy, anti-surveillance and dignity controls, central green identity, Master-Plan-first workflow, visible evidence, repeated review/fix until zero known defects, comprehensive GitHub review and truthful status separation.
3. **CF-05 Conditional Complete Master Plan 2026 v1.0 — Analytics, Metrics and Institutional Intelligence** — conditional activation, event governance, derivative warehouse, lineage, quality, metric semantics, privacy-safe queries, reports, experiments, deletion, provider exit and restore.

## Cross-plan implementation map

| Governing concern | Source implementation/evidence |
|---|---|
| Canonical derivative ownership; no native-domain takeover | `MANIFEST.json`, `IntegrationRegistry`, `docs/REQUIREMENTS-TRACEABILITY.md` |
| Conditional and evidence-bound activation | `RuntimeActivationService`, `RuntimeGate`, schema-version and migration-error gates |
| Islamic privacy, dignity and anti-surveillance | allowlist ingestion, pseudonymization, purpose-bound access, minimum cohorts, query privacy budget, differencing guard, short retention |
| No raw clinical/message/identity/payment-secret analytics | validators, `SensitiveValueDetector`, `PrivacyGateway`, static secret/prohibited-data tests |
| Event contracts, corrections, late facts, replay/idempotency | `EventSchemaRegistry`, `EventIngestionService`, `ServiceAuthenticator`, source sequence uniqueness |
| Derivative models, historical semantics and lineage | `DatasetCatalog`, `PipelineService`, `TransformationEngine`, `LineageService`, `CheckpointService` |
| Quality, backfill, rollback and reproducibility | `QualityService`, `BackfillService`, `SnapshotService`, `RestoreService` |
| Metric definition closure and privacy-safe queries | `MetricDefinitionValidator`, `MetricCatalog`, `MetricQueryService`, `PrivacyQueryPolicy`, `QueryPrivacyGuard` |
| Reports/exports revocation and expiry | `ReportService`, `ReportControlService`, `ExportService`, `ExportControlService`, `RetentionRunner` |
| Human-governed experiments and decisions | `ExperimentService`, `NarrativeService`; no automatic native action command |
| Deletion/provider exit/restore | `DeletionService`, `ProviderService`, `RestoreService`; legacy missing keys create critical quality issue |
| Accessibility and central green identity | `InsightsShortcode`, `assets/css/insights.css`, unique IDs, keyboard focus and reduced motion |
| Least privilege and separation of duties | dedicated analytics roles plus independent-actor checks and row-version concurrency |
| Evidence-only completion status | health/manifest/PR distinguish coded, staged, live and operational states |
| Forty fresh review/fix rounds | `docs/REVIEW-ROUNDS-07-46.md` and exact-head CI evidence |

No source document authorizes a claim of staging acceptance, live deployment or operational completion merely from code or CI; those gates remain external.
