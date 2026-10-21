# CF-05 — SR310–349: Forty Fresh Scoped Reviews (21 October 2026)

Governing references: CF-05 v1.1 (Future40 Amended, 16 September 2026); Three Central Governing Plans; Public Traffic Analytics and Disease Intelligence; File 16, File 17, File 18, CF-01, CF-02 and CF-06.

Immutable baseline: `43d5c6545ce7be651b89f5bfae5ea9c930451056`, version `1.0.0-rc.14`. All forty requirements were mapped to existing source targets. **Source-target presence is NOT an exhaustive function check or production acceptance.**

| Review | Plan ID | Subject | Source target | Evidence limit |
|---|---|---|---|---|
| SR-310 | CF05-FR-001 | Event schema registry | `src/Domain/EventSchemaRegistry.php` | Source location checked; E2E unverified |
| SR-311 | CF05-FR-002 | Minimum event envelope | `src/Domain/EventIngestionService.php` | Source location checked; E2E unverified |
| SR-312 | CF05-FR-003 | Purpose/consent gate | `src/Domain/EventIngestionService.php` | Source location checked; E2E unverified |
| SR-313 | CF05-FR-004 | Field redaction/tokenization | `src/Domain/PrivacyGateway.php` | Source location checked; E2E unverified |
| SR-314 | CF05-FR-005 | Dedupe/order/late events | `src/Domain/EventIngestionService.php` | Source location checked; E2E unverified |
| SR-315 | CF05-FR-006 | Quarantine and feedback | `src/Domain/EventIngestionService.php` | Source location checked; E2E unverified |
| SR-316 | CF05-FR-007 | Canonical derivative modeling | `src/Domain/DatasetCatalog.php` | Source location checked; E2E unverified |
| SR-317 | CF05-FR-008 | Slowly changing definitions | `src/Contracts/DatasetDefinitionValidator.php` | Source location checked; E2E unverified |
| SR-318 | CF05-FR-009 | Data lineage | `src/Domain/LineageService.php` | Source location checked; E2E unverified |
| SR-319 | CF05-FR-010 | Backfill/rebuild | `src/Domain/BackfillService.php` | Source location checked; E2E unverified |
| SR-320 | CF05-FR-011 | Data quality rules | `src/Domain/QualityService.php` | Source location checked; E2E unverified |
| SR-321 | CF05-FR-012 | Region/provider control | `src/Domain/ProviderService.php` | Source location checked; E2E unverified |
| SR-322 | CF05-FR-013 | Metric definition | `src/Contracts/MetricDefinitionValidator.php` | Source location checked; E2E unverified |
| SR-323 | CF05-FR-014 | Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | Source location checked; E2E unverified |
| SR-324 | CF05-FR-015 | Dimension allowlist | `src/Domain/MetricQueryService.php` | Source location checked; E2E unverified |
| SR-325 | CF05-FR-016 | Freshness and confidence | `src/Domain/SnapshotDisclosurePolicy.php` | Source location checked; E2E unverified |
| SR-326 | CF05-FR-017 | Attribution and funnels | `src/Domain/FunnelAttributionCalculator.php` | Source location checked; E2E unverified |
| SR-327 | CF05-FR-018 | Financial/clinical metric limits | `src/Infrastructure/SensitiveValueDetector.php` | Source location checked; E2E unverified |
| SR-328 | CF05-FR-019 | Role/project-scoped dashboards | `src/Domain/DashboardService.php` | Source location checked; E2E unverified |
| SR-329 | CF05-FR-020 | Metric presentation | `src/Presentation/InsightsShortcode.php` | Source location checked; E2E unverified |
| SR-330 | CF05-FR-021 | Drilldown safety | `src/Domain/QueryPrivacyGuard.php` | Source location checked; E2E unverified |
| SR-331 | CF05-FR-022 | Scheduled reports | `src/Domain/ReportService.php` | Source location checked; E2E unverified |
| SR-332 | CF05-FR-023 | Secure exports | `src/Domain/ExportService.php` | Source location checked; E2E unverified |
| SR-333 | CF05-FR-024 | Narrative insights | `src/Domain/NarrativeService.php` | Source location checked; E2E unverified |
| SR-334 | CF05-FR-025 | Experiment registry | `src/Domain/ExperimentService.php` | Source location checked; E2E unverified |
| SR-335 | CF05-FR-026 | Assignment separation | `src/Domain/ExperimentService.php` | Source location checked; E2E unverified |
| SR-336 | CF05-FR-027 | Guardrail monitoring | `src/Domain/ExperimentService.php` | Source location checked; E2E unverified |
| SR-337 | CF05-FR-028 | Statistical integrity | `src/Domain/Statistics.php` | Source location checked; E2E unverified |
| SR-338 | CF05-FR-029 | Decision record | `src/Domain/ExperimentService.php` | Source location checked; E2E unverified |
| SR-339 | CF05-FR-030 | Retention tiers | `src/Infrastructure/RetentionRunner.php` | Source location checked; E2E unverified |
| SR-340 | CF05-FR-031 | Deletion/anonymization propagation | `src/Domain/DeletionService.php` | Source location checked; E2E unverified |
| SR-341 | CF05-FR-032 | Access projects and expiry | `src/Domain/AccessProjectService.php` | Source location checked; E2E unverified |
| SR-342 | CF05-FR-033 | Query and export audit | `src/Infrastructure/AuditLogger.php` | Source location checked; E2E unverified |
| SR-343 | CF05-FR-034 | Provider/warehouse exit | `src/Domain/ProviderService.php` | Source location checked; E2E unverified |
| SR-344 | CF05-FR-035 | Restore and reproducibility | `src/Domain/RestoreService.php` | Source location checked; E2E unverified |
| SR-345 | CROSS-01 | Central canonical ownership | `MANIFEST.json` | Source location checked; E2E unverified |
| SR-346 | CROSS-02 | File 16/17/18 and CF-01/02 sensitive exclusion | `src/Infrastructure/SensitiveValueDetector.php` | Source location checked; E2E unverified |
| SR-347 | CROSS-03 | Public traffic and ranking separation | `src/Infrastructure/IntegrationRegistry.php` | Source location checked; E2E unverified |
| SR-348 | CROSS-04 | Future40 separate approvals | `src/Infrastructure/FutureActivationService.php` | Source location checked; E2E unverified |
| SR-349 | CROSS-05 | Schema, migrations, staging/live parity | `MANIFEST.json` | Source location checked; E2E unverified |

## Frozen findings before changes — four actual code defects

1. **D310-01 (availability/integrity)**: a failed DB read of dashboard_widgets could produce a successful empty dashboard response. Corrected to fail closed with 503.
2. **D310-02 (high/integrity)**: failed dashboard_widgets read during activation could be treated as an empty success. Corrected to fail closed with 503.
3. **D310-03 (integrity)**: an active project/dashboard with zero persisted widget records could pass activation despite registration requiring at least one. Corrected with 409 integrity gate.
4. **D310-04 (high/privacy)**: historical privacy witness with valid-shaped slice fingerprint but absent/invalid time, dimension names or keyed dimension hashes could bypass meaningful differencing comparison. Corrected with full prior-witness shape validation, deterministic window order, unique names and digest validation.

The baseline exact ZIP failed **5/5 adversarial assertions**. The locally patched PHP passed 5/5. The tests are now mandatory `tests/sr310-349-dashboard-privacy.php` in `scripts/qa.sh`. GitHub Actions and package verification are additional evidence and must match the final exact HEAD.

The remaining five cross-file reviews inspected owner boundaries but did **not** verify native producer runtime contracts or deployed functionality.

**External blockers:** actual WordPress active plugin/deployed package checksum; live DB/schema and migration state; File 00/05/16/17/18/19/20/23/24/25/26 and CF-01/02/03/06 service/event contracts; staging role/privacy/a11y/load tests; backup-restore/reversal; Founder approval; live deploy/retest. A prior WordPress connector path returned Cloudflare 530 (cause unproven). Do not disable protections based on this response.

Mandatory incident fields: Repository HEAD = record after final exact-head CI; Deployed Version = unverified; DB Version = unverified; Migration State = unverified; Live Verification Status = not performed.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
