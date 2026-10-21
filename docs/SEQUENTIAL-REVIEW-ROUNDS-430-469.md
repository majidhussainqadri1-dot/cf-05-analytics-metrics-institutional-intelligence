# CF-05 — Forty New Scoped Reviews SR-430–469

**Review date:** 21 October 2026. **Frozen repository baseline:** `d47d5a9905f42b848f4b5a5b4d7f026cc711df77`, release candidate `1.0.0-rc.17`.

**Controlling sources:** CF-05 v1.1 (Future-40 Amended 2026-09-16); Three Central Consolidated Governing Plans; separate public Traffic Analytics and Disease Intelligence; companion File 16 (AI), File 17 (Communication), File 18 (Marketplace), CF-01 (Clinical), CF-02 (Support), CF-06 (Localization) plans.

**Method and limitations:** Each of the thirty-five governed CF05-FR-001..035 subjects and five cross-plan/privacy/release boundaries was independently mapped to a source file and two relevant implementation witnesses. The plan's section 5 supplies the 35 FR identities. A code marker is evidence of source presence, **not evidence that the corresponding feature works end to end**, and is not proof that companion systems expose approved live contracts.

| Review | Governing ID | Plan subject | Source inspected | Source witnesses |
|---|---|---|---|---|
| SR-430 | CF05-FR-001 | Event schema registry | `src/Domain/EventSchemaRegistry.php` | `event_name`, `version` |
| SR-431 | CF05-FR-002 | Minimum event envelope | `src/Domain/EventIngestionService.php` | `event_id`, `source_environment` |
| SR-432 | CF05-FR-003 | Purpose/consent gate | `src/Domain/EventIngestionService.php` | `consent`, `purpose` |
| SR-433 | CF05-FR-004 | Field redaction/tokenization | `src/Domain/PrivacyGateway.php` | `pseudonym`, `unknown_field` |
| SR-434 | CF05-FR-005 | Dedupe/order/late events | `src/Domain/EventIngestionService.php` | `sequence`, `source_sequence_key` |
| SR-435 | CF05-FR-006 | Quarantine and feedback | `src/Domain/EventIngestionService.php` | `quarantine`, `reason` |
| SR-436 | CF05-FR-007 | Canonical derivative modeling | `src/Domain/DatasetCatalog.php` | `dataset`, `source` |
| SR-437 | CF05-FR-008 | Slowly changing definitions | `src/Contracts/DatasetDefinitionValidator.php` | `version`, `histor` |
| SR-438 | CF05-FR-009 | Data lineage | `src/Domain/LineageService.php` | `lineage`, `owner` |
| SR-439 | CF05-FR-010 | Backfill/rebuild | `src/Domain/BackfillService.php` | `backfill`, `rollback` |
| SR-440 | CF05-FR-011 | Data quality rules | `src/Domain/QualityService.php` | `quality`, `freshness` |
| SR-441 | CF05-FR-012 | Region/provider control | `src/Domain/ProviderService.php` | `provider`, `region` |
| SR-442 | CF05-FR-013 | Metric definition | `src/Contracts/MetricDefinitionValidator.php` | `numerator`, `denominator` |
| SR-443 | CF05-FR-014 | Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | `transition`, `metric` |
| SR-444 | CF05-FR-015 | Dimension allowlist | `src/Domain/MetricQueryService.php` | `allowedDimensions`, `dimension` |
| SR-445 | CF05-FR-016 | Freshness and confidence | `src/Domain/SnapshotDisclosurePolicy.php` | `freshness`, `caveat` |
| SR-446 | CF05-FR-017 | Attribution and funnels | `src/Domain/FunnelAttributionCalculator.php` | `conversion`, `first_touch` |
| SR-447 | CF05-FR-018 | Financial/clinical metric limits | `src/Infrastructure/SensitiveValueDetector.php` | `clinical`, `payment` |
| SR-448 | CF05-FR-019 | Role/project-scoped dashboards | `src/Domain/DashboardService.php` | `authorizesViewer`, `audience` |
| SR-449 | CF05-FR-020 | Metric presentation | `src/Presentation/InsightsShortcode.php` | `shortcode`, `metric` |
| SR-450 | CF05-FR-021 | Drilldown safety | `src/Domain/QueryPrivacyGuard.php` | `differencing`, `budget` |
| SR-451 | CF05-FR-022 | Scheduled reports | `src/Domain/ReportService.php` | `scheduleDue`, `recipientScoped` |
| SR-452 | CF05-FR-023 | Secure exports | `src/Domain/ExportService.php` | `CryptoBox`, `CSV` |
| SR-453 | CF05-FR-024 | Narrative insights | `src/Domain/NarrativeService.php` | `narrative`, `evidence` |
| SR-454 | CF05-FR-025 | Experiment registry | `src/Domain/ExperimentService.php` | `experiment`, `hypothesis` |
| SR-455 | CF05-FR-026 | Assignment separation | `src/Domain/ExperimentService.php` | `assignment`, `owner` |
| SR-456 | CF05-FR-027 | Guardrail monitoring | `src/Domain/ExperimentService.php` | `guardrail`, `stop` |
| SR-457 | CF05-FR-028 | Statistical integrity | `src/Domain/Statistics.php` | `proportionInterval`, `twoSidedNormalPValue` |
| SR-458 | CF05-FR-029 | Decision record | `src/Domain/ExperimentService.php` | `decision`, `audit` |
| SR-459 | CF05-FR-030 | Retention tiers | `src/Infrastructure/RetentionRunner.php` | `retention`, `expire` |
| SR-460 | CF05-FR-031 | Deletion/anonymization propagation | `src/Domain/DeletionService.php` | `deletion`, `revoke` |
| SR-461 | CF05-FR-032 | Access projects and expiry | `src/Domain/AccessProjectService.php` | `authorizesViewer`, `expires_at` |
| SR-462 | CF05-FR-033 | Query and export audit | `src/Infrastructure/AuditLogger.php` | `audit`, `logInOpenTransaction` |
| SR-463 | CF05-FR-034 | Provider/warehouse exit | `src/Domain/ProviderService.php` | `provider`, `exit` |
| SR-464 | CF05-FR-035 | Restore and reproducibility | `src/Domain/RestoreService.php` | `restore`, `catalog_hash` |
| SR-465 | CROSS-01 | Central owner/derivative boundary | `MANIFEST.json` | `non_goals`, `privacy` |
| SR-466 | CROSS-02 | AI, communication and marketplace event separation | `src/Infrastructure/SensitiveValueDetector.php` | `message`, `payment` |
| SR-467 | CROSS-03 | Clinical/support/localization ownership boundaries | `src/Infrastructure/IntegrationRegistry.php` | `owner`, `contract` |
| SR-468 | CROSS-04 | Future-40 separate approval/activation | `src/Infrastructure/FutureActivationService.php` | `activation`, `approval` |
| SR-469 | CROSS-05 | Schema/migration/staging/live parity | `MANIFEST.json` | `live_deployed`, `schema_version` |

## Frozen new defects (not counted again from previous review cycles)

**CF05-D430-01 — High / privacy differencing:** The prior `PrivacyQueryPolicy::differencingRisk` returned **false** for identical metric windows/dimension signatures even when an approved snapshot's cohort size changed by 1–19 people. Requerying corrected slices can therefore disclose a small group by subtraction. Root fix: an unchanged cohort remains an exact repeat, while a nonzero delta smaller than the configured privacy floor is suppressed. A delta at or above the approved floor preserves intended result.

**CF05-D430-02 — High / job correctness:** `JobQueue::enqueue` on an existing idempotency key checked only UUID and state. A collision referencing a *different job type or payload* falsely returned a successful duplicate, silently skipping the requested work. Root fix: read back `job_type,payload_json`; compare exact job type and canonical decoded JSON payload. Reject missing/corrupt/mismatched stored details with `smai_job_idempotency_payload_conflict` rather than success.

**Actual adversarial evidence:** Nine newly targeted tests on immutable rc.17 passed **3/9**; six failed. The candidate fix passed **9/9** in local PHP, including no-regression positive cases. The prior SR390–429 duplicate fixture has also been updated to include real immutable job metadata; all mandatory GitHub QA requires a new exact-HEAD run.

**Data-model and public-contract version:** `schema_version=1.4.1`, `contract_version=1.4.0`. These remain target/source declarations, not inspected live DB reality.

## External blockers still open

- Exact installed WordPress plugin/deployed artifact SHA-256, wp_options, DB schema/migrations, active dependencies and logs unverified.
- Real source/consumer event and consent/minor/deletion contracts across Files 00/05/16/17/18/19/20/23/24/25/26 and conditional CF-01/02/03/06 require staged integration evidence.
- Data Governance Council activation, safety/privacy review, restore/rollback, role journeys, accessibility/RTL, load and security/penetration acceptance, Founder approval, deployment and live retest remain pending.
- Historical WPVibe connection failed with Cloudflare HTTP 530. This status alone does **not** prove a bot issue, DNS/origin root cause or that disabling any security layer is warranted.

**Mandatory live-state fields:** Repository HEAD = confirm final PR head after source release; Deployed Version = unverified; DB Version = unverified; Migration State = unverified; Live Verification Status = unverified.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
