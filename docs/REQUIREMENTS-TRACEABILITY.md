# CF-05 Requirements Traceability — 1.0.0-rc.10

**Fresh plan-accurate reconciliation: 21 October 2026.**

The 35 identifiers and labels below follow **the attached 52-page CF-05 v1.1 Future40 amended governing Word document**, sections 5.1–5.6, rather than the earlier repository's mismatched ID-to-label table. The linked source anchor shows a plausible implementation owner; **file presence or a CI static check does not prove the entire clinical/privacy/warehouse journey has been implemented or accepted**. Runtime/provider/warehouse/security acceptance remains a separate gate. The companion `scripts/fresh40-plan-parity-20261021.py` checks 35 owner anchors plus five governing contract boundaries.

| Requirement | Governing functional requirement | Candidate source owner | Implementation acceptance status |
|---|---|---|---|
| CF05-FR-001 | Event schema registry | `src/Domain/EventSchemaRegistry.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-002 | Minimum event envelope | `src/Domain/EventIngestionService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-003 | Purpose / consent gate | `src/Domain/PrivacyGateway.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-004 | Field redaction / tokenization | `src/Domain/PrivacyGateway.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-005 | Dedupe, order and late events | `src/Domain/EventIngestionService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-006 | Quarantine and owner feedback | `src/Domain/EventIngestionService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-007 | Derivative model / canonical IDs | `src/Domain/DatasetCatalog.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-008 | Slowly changing historical definitions | `src/Domain/DatasetCatalog.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-009 | Data lineage | `src/Domain/LineageService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-010 | Backfill and shadow build | `src/Domain/BackfillService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-011 | Data quality rules | `src/Domain/QualityService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-012 | Region and provider control | `src/Domain/ProviderService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-013 | Semantic metric definition | `src/Contracts/MetricDefinitionValidator.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-014 | Metric lifecycle and versioning | `src/Domain/MetricCatalog.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-015 | Dimension allowlist | `src/Domain/PrivacyQueryPolicy.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-016 | Freshness and confidence | `src/Domain/MetricQueryService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-017 | Attribution and funnels | `src/Domain/SnapshotService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-018 | Finance and clinical aggregate limits | `src/Domain/PrivacyQueryPolicy.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-019 | Project-scoped dashboards | `src/Domain/DashboardService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-020 | Metric presentation | `src/Domain/MetricQueryService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-021 | Drill-down, tiny cohort and differencing safety | `src/Domain/QueryPrivacyGuard.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-022 | Scheduled reports | `src/Domain/ReportService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-023 | Secure exports | `src/Domain/ExportService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-024 | Evidence-linked narrative insights | `src/Domain/NarrativeService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-025 | Experiment registry | `src/Domain/ExperimentService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-026 | Assignment owner separation | `src/Domain/ExperimentService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-027 | Experiment guardrail monitoring | `src/Domain/ExperimentService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-028 | Statistical integrity | `src/Domain/Statistics.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-029 | Human decision record | `src/Domain/ExperimentService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-030 | Retention tiers | `src/Infrastructure/RetentionRunner.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-031 | Deletion propagation | `src/Domain/DeletionService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-032 | Access-project expiry | `src/Domain/AccessProjectService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-033 | Query and export audit | `src/Domain/MetricQueryService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-034 | Provider exit | `src/Domain/ProviderService.php` | Source anchor; E2E/staging NOT VERIFIED |
| CF05-FR-035 | Restore and reproducibility | `src/Domain/RestoreService.php` | Source anchor; E2E/staging NOT VERIFIED |

## Governing constraints

- CF05-FUT-001..040 remain individually disabled absent independent feature approvals; feature code does not imply activation.
- Source has no right to write canonical File 00/02/23/26, CF-01, CF-02, CF-03 domain truth; approved minimized versioned events and deletion contracts only.
- No raw clinical notes, prescriptions, private messages, identity evidence, card data, OTP, unrestricted private query texts or donor/non-donor ranking identity into analytics.
- Metric snapshots must use minimum cohort, dedupe, version pinning, freshness and audit; never autonomous financial/medical/moderation or search/ranking actions.
- Any undocumented owner/consumer contract is a **blocker**, not permission to invent an API or claim release acceptance.
- This corrects the earlier repository row-label mismatch; the old mapping must not be used as a claim that all 35 functional requirements are acceptance-complete.

## Live Truth Ledger

| Evidence | Status |
|---|---|
| GitHub `main` baseline | `3dd37bed22c86403b007653cff53ffbe8c1a0225` — README only |
| Reviewed implementation baseline | `6f200ba220724e0331b25c97af558d1906b55b58` (`1.0.0-rc.10`) |
| Current audit branch exact HEAD | Verify in GitHub, not inferred from this document |
| Deployed Version / DB Schema / Migration State | UNKNOWN / UNVERIFIED / UNVERIFIED |
| Live Verification Status | NOT PERFORMED |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
