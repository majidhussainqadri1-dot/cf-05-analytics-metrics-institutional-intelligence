# CF-05 — Forty Fresh Source-Scope Reviews SR-230…SR-269 (2026-10-21)

## Source-of-truth freeze

- **Exact baseline under review:** `70a65806e0140bac1b63c1279928b287b9b74e09`, package `1.0.0-rc.12`, schema target `1.4.1`, public contract target `1.4.0`. The baseline GitHub Actions artifact `12328606014` was independently unpacked and its inner ZIP SHA-256 verified against its checksum manifest.
- **Source hierarchy:** founder's CF-05 v1.1 Future40 Amended 2026-09-16 Word plan; three consolidated central master plans; public traffic/disease intelligence separation; Files 16/17/18 and CF-01/02/03/06 conditional companion plans. Canonical domain truth, private data, payments, clinical care and ranking remain with their original owners.
- **Audit method:** 40 separate plan-mapped static source checks, plus adversarial runtime inputs and cross-output privacy reasoning. All 40 source locations/required markers were located after correcting one *audit-script mapping* (FR-002 belongs to envelope ingestion, not the schema-definition validator). This correction is **not** a product-code defect.
- **Limitations:** source-marker confirmation is not end-to-end function certification. It does not prove active WordPress schemas, services, data-provider contracts, Hostinger staging, or deployment.

## New forty check ledger

| Review | Governed source | Primary code inspected | Source audit / limitation |
|---|---|---|---|
| SR-230 | CF05-FR-001 — Event schema registry | `EventSchemaRegistry.php` | Source target located; functional and staging acceptance remains separate |
| SR-231 | CF05-FR-002 — Minimum event envelope | `EventIngestionService.php` | Source target located; functional and staging acceptance remains separate |
| SR-232 | CF05-FR-003 — Purpose/consent gate | `EventIngestionService.php` | Source target located; functional and staging acceptance remains separate |
| SR-233 | CF05-FR-004 — Field redaction/tokenization | `PrivacyGateway.php` | Source target located; functional and staging acceptance remains separate |
| SR-234 | CF05-FR-005 — Dedupe/order/late events | `EventIngestionService.php` | Source target located; functional and staging acceptance remains separate |
| SR-235 | CF05-FR-006 — Quarantine and feedback | `EventIngestionService.php` | Source target located; functional and staging acceptance remains separate |
| SR-236 | CF05-FR-007 — Canonical derivative modeling | `DatasetCatalog.php` | Source target located; functional and staging acceptance remains separate |
| SR-237 | CF05-FR-008 — Slowly changing definitions | `PipelineService.php` | Source target located; functional and staging acceptance remains separate |
| SR-238 | CF05-FR-009 — Data lineage | `LineageService.php` | Source target located; functional and staging acceptance remains separate |
| SR-239 | CF05-FR-010 — Backfill/rebuild | `BackfillService.php` | Source target located; functional and staging acceptance remains separate |
| SR-240 | CF05-FR-011 — Data quality rules | `QualityService.php` | Source target located; functional and staging acceptance remains separate |
| SR-241 | CF05-FR-012 — Region/provider control | `ProviderService.php` | Source target located; functional and staging acceptance remains separate |
| SR-242 | CF05-FR-013 — Metric definition | `MetricDefinitionValidator.php` | Source target located; functional and staging acceptance remains separate |
| SR-243 | CF05-FR-014 — Metric lifecycle | `CatalogLifecycleService.php` | Source target located; functional and staging acceptance remains separate |
| SR-244 | CF05-FR-015 — Dimension allowlist | `MetricQueryService.php` | Source target located; functional and staging acceptance remains separate |
| SR-245 | CF05-FR-016 — Freshness and confidence | `SnapshotDisclosurePolicy.php` | Source target located; functional and staging acceptance remains separate |
| SR-246 | CF05-FR-017 — Attribution and funnels | `FunnelAttributionCalculator.php` | Source target located; functional and staging acceptance remains separate |
| SR-247 | CF05-FR-018 — Financial/clinical metric limits | `SensitiveValueDetector.php` | Source target located; functional and staging acceptance remains separate |
| SR-248 | CF05-FR-019 — Role/project-scoped dashboards | `DashboardService.php` | Source target located; functional and staging acceptance remains separate |
| SR-249 | CF05-FR-020 — Metric presentation | `InsightsShortcode.php` | Source target located; functional and staging acceptance remains separate |
| SR-250 | CF05-FR-021 — Drilldown safety | `QueryPrivacyGuard.php` | Source target located; functional and staging acceptance remains separate |
| SR-251 | CF05-FR-022 — Scheduled reports | `ReportService.php` | Source target located; functional and staging acceptance remains separate |
| SR-252 | CF05-FR-023 — Secure exports | `ExportService.php` | Source target located; functional and staging acceptance remains separate |
| SR-253 | CF05-FR-024 — Narrative insights | `NarrativeService.php` | Source target located; functional and staging acceptance remains separate |
| SR-254 | CF05-FR-025 — Experiment registry | `ExperimentService.php` | Source target located; functional and staging acceptance remains separate |
| SR-255 | CF05-FR-026 — Assignment separation | `ExperimentService.php` | Source target located; functional and staging acceptance remains separate |
| SR-256 | CF05-FR-027 — Guardrail monitoring | `ExperimentService.php` | Source target located; functional and staging acceptance remains separate |
| SR-257 | CF05-FR-028 — Statistical integrity | `Statistics.php` | Source target located; functional and staging acceptance remains separate |
| SR-258 | CF05-FR-029 — Decision record | `ExperimentService.php` | Source target located; functional and staging acceptance remains separate |
| SR-259 | CF05-FR-030 — Retention tiers | `RetentionRunner.php` | Source target located; functional and staging acceptance remains separate |
| SR-260 | CF05-FR-031 — Deletion/anonymization propagation | `DeletionService.php` | Source target located; functional and staging acceptance remains separate |
| SR-261 | CF05-FR-032 — Access projects and expiry | `AccessProjectService.php` | Source target located; functional and staging acceptance remains separate |
| SR-262 | CF05-FR-033 — Query and export audit | `AuditLogger.php` | Source target located; functional and staging acceptance remains separate |
| SR-263 | CF05-FR-034 — Provider/warehouse exit | `ProviderService.php` | Source target located; functional and staging acceptance remains separate |
| SR-264 | CF05-FR-035 — Restore and reproducibility | `RestoreService.php` | Source target located; functional and staging acceptance remains separate |
| SR-265 | CROSS-01 — Central single owner and anti-surveillance | `IntegrationRegistry.php` | Source target located; cross-owner/runtime proof remains external |
| SR-266 | CROSS-02 — File 16 AI / 17 communication / 18 marketplace / CF-01 clinical | `SensitiveValueDetector.php` | Source target located; cross-owner/runtime proof remains external |
| SR-267 | CROSS-03 — CF-02 support / CF-03 financial / public traffic owner boundaries | `MetricCatalog.php` | Source target located; cross-owner/runtime proof remains external |
| SR-268 | CROSS-04 — Forty Future-40 consent/approval/disabled gates | `FutureActivationService.php` | Source target located; cross-owner/runtime proof remains external |
| SR-269 | CROSS-05 — Schema, migrations, provider integrations, staging, release and live parity | `RuntimeGate.php` | Source target located; cross-owner/runtime proof remains external |

## Eight new, confirmed baseline defects

| ID | Severity | Baseline defect / evidence | Applied code correction | Post-fix evidence |
|---|---|---|---|---|
| D-230-01 | High/privacy | Dashboard read published metric snapshots outside `QueryPrivacyGuard`, evading differencing/slice/budget constraints | Query guard invoked before disclosure; safe suppression on failed accounting | CI regression source assertions |
| D-230-02 | High/privacy | Scheduled reports bypassed query privacy accounting | Report snapshot consumption guarded at generation; unavailable result on blocked privacy | CI regression source assertions |
| D-230-03 | High/privacy | CSV export scanned snapshot rows without privacy budget/differencing gate | Each candidate output row accounted; failed guard fails closed | CI regression source assertions |
| D-230-04 | Medium/integrity | DB failure in export snapshot read was returned as an empty successful result | Throw on DB read failure, enabling safe worker failure/retry instead of false-green export | CI regression source assertions |
| D-230-05 | Medium/availability | Snapshot idempotency key omitted actor identity while worker re-checked original actor, potentially blocking a new authorized requester | Bind actor to snapshot enqueue idempotency key | CI regression source assertions |
| D-230-06 | Medium/availability | Quality-run idempotency omitted actor identity while worker enforced requester's current capability | Bind actor to Quality enqueue key | CI regression source assertions |
| D-230-07 | Medium/measurement | Simultaneous same-time distinct conversion channels yielded input-order-dependent last-touch credit | Skip ambiguous earliest conversion with discordant channels | Direct PHP red/green fixture for reversed event order |
| D-230-08 | High/privacy | Query budgets were actor-isolated, allowing viewers of one project to rotate actor identities | Aggregate query ledger by governed project + metric while keeping individual audit identities | CI regression source assertions |

### Proof boundaries

The baseline was subjected to seven static boundary regressions plus an input-order reversal fixture: these failed before modification. New `tests/sr230-269-privacy-measurement.php` preserves eleven fixed-head assertions including the positive nonambiguous conversion case. The PHP syntax checks and GitHub Actions matrix prove repository candidate quality, not real provider correctness.

**Open external gates:** real File 00/05/16/17/18/19/20/23/24/25/26 and conditional clinical/support/billing/data contracts; provider/region privacy and deletion propagation; actual WordPress installed plugin/version/DB and migration state; staging/rollback/load/accessibility/security acceptance; Founder change approval and monitored live deployment/re-test. Cross-project collusion, adversarial composition of different metrics, real cohort leakage and high-volume operational memory behavior still require targeted integration/penetration tests; do not treat limited source tests as a privacy certification.

**Mandatory life-cycle truth:** Repository HEAD = read PR head after final CI; Deployed Version = unverified; DB Version = unverified; Migration State = unverified; Live Verification Status = unverified.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
