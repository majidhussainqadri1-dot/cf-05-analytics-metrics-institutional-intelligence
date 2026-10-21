# CF-05 — 40 Fresh Scoped Source Reviews SR-390 to SR-429

## Freeze and legal hierarchy

Baseline source: `76fc5ae081ce7cc00fa2c87f4e5c1843d4f27626`, release `1.0.0-rc.16`. Governing specifications: CF-05 amended 2026-09-16 plan; Three Central Consolidated Master Plans; separately owned Traffic Analytics/Disease Intelligence; Files 16 AI, 17 Communication, 18 Marketplace, CF-01 Clinical, CF-02 Support, CF-06 Localization. Canonical owner, privacy and permission authority remain with original modules.

**Evidence definitions:** Forty different plan/feature-scoped source reviews, each checking two specific source markers. They do NOT constitute 40 executed functional end-to-end acceptance tests. Eight additional real adversarial PHP assertions address proven defects and are mandatory CI.

| Round | Requirement | Subject | Examined file | Static evidence / limitation |
|---|---|---|---|---|
| SR-390 | CF05-FR-001 | Event schema registry | `src/Domain/EventSchemaRegistry.php` | `schema` + `function register` present; real cross-file integration pending |
| SR-391 | CF05-FR-002 | Minimum event envelope | `src/Domain/EventIngestionService.php` | `event_id` + `function ingest` present; real cross-file integration pending |
| SR-392 | CF05-FR-003 | Purpose/consent gate | `src/Domain/EventIngestionService.php` | `consent` + `consent` present; real cross-file integration pending |
| SR-393 | CF05-FR-004 | Field redaction/tokenization | `src/Domain/PrivacyGateway.php` | `pseudonymize` + `function pseudonymousRef` present; real cross-file integration pending |
| SR-394 | CF05-FR-005 | Dedupe/order/late events | `src/Domain/EventIngestionService.php` | `sequence` + `sequence` present; real cross-file integration pending |
| SR-395 | CF05-FR-006 | Quarantine and feedback | `src/Domain/EventIngestionService.php` | `quarantine` + `function quarantine` present; real cross-file integration pending |
| SR-396 | CF05-FR-007 | Canonical derivative modeling | `src/Domain/DatasetCatalog.php` | `dataset` + `function published` present; real cross-file integration pending |
| SR-397 | CF05-FR-008 | Slowly changing definitions | `src/Contracts/DatasetDefinitionValidator.php` | `version` + `function errors` present; real cross-file integration pending |
| SR-398 | CF05-FR-009 | Data lineage | `src/Domain/LineageService.php` | `lineage` + `function link` present; real cross-file integration pending |
| SR-399 | CF05-FR-010 | Backfill/rebuild | `src/Domain/BackfillService.php` | `backfill` + `function dryRun` present; real cross-file integration pending |
| SR-400 | CF05-FR-011 | Data quality rules | `src/Domain/QualityService.php` | `quality` + `function evaluate` present; real cross-file integration pending |
| SR-401 | CF05-FR-012 | Region/provider control | `src/Domain/ProviderService.php` | `region` + `function transition` present; real cross-file integration pending |
| SR-402 | CF05-FR-013 | Metric definition | `src/Contracts/MetricDefinitionValidator.php` | `numerator` + `function errors` present; real cross-file integration pending |
| SR-403 | CF05-FR-014 | Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | `state` + `function transition` present; real cross-file integration pending |
| SR-404 | CF05-FR-015 | Dimension allowlist | `src/Domain/MetricQueryService.php` | `allowedDimensions` + `function query` present; real cross-file integration pending |
| SR-405 | CF05-FR-016 | Freshness and confidence | `src/Domain/SnapshotDisclosurePolicy.php` | `freshness` + `function evaluate` present; real cross-file integration pending |
| SR-406 | CF05-FR-017 | Attribution and funnels | `src/Domain/FunnelAttributionCalculator.php` | `last_touch` + `window_seconds` present; real cross-file integration pending |
| SR-407 | CF05-FR-018 | Financial/clinical metric limits | `src/Infrastructure/SensitiveValueDetector.php` | `clinical` + `forbiddenKey` present; real cross-file integration pending |
| SR-408 | CF05-FR-019 | Role/project-scoped dashboards | `src/Domain/DashboardService.php` | `audienceAllows` + `function bundle` present; real cross-file integration pending |
| SR-409 | CF05-FR-020 | Metric presentation | `src/Presentation/InsightsShortcode.php` | `shortcode` + `function render` present; real cross-file integration pending |
| SR-410 | CF05-FR-021 | Drilldown safety | `src/Domain/QueryPrivacyGuard.php` | `differencing` + `function evaluateAndRecord` present; real cross-file integration pending |
| SR-411 | CF05-FR-022 | Scheduled reports | `src/Domain/ReportService.php` | `scheduleDue` + `function accessDelivery` present; real cross-file integration pending |
| SR-412 | CF05-FR-023 | Secure exports | `src/Domain/ExportService.php` | `CryptoBox` + `function download` present; real cross-file integration pending |
| SR-413 | CF05-FR-024 | Narrative insights | `src/Domain/NarrativeService.php` | `narrative` + `function publish` present; real cross-file integration pending |
| SR-414 | CF05-FR-025 | Experiment registry | `src/Domain/ExperimentService.php` | `experiment` + `function create` present; real cross-file integration pending |
| SR-415 | CF05-FR-026 | Assignment separation | `src/Domain/ExperimentService.php` | `assignment` + `function recordAssignment` present; real cross-file integration pending |
| SR-416 | CF05-FR-027 | Guardrail monitoring | `src/Domain/ExperimentService.php` | `guardrail` + `function analyze` present; real cross-file integration pending |
| SR-417 | CF05-FR-028 | Statistical integrity | `src/Domain/Statistics.php` | `proportionInterval` + `function compareProportions` present; real cross-file integration pending |
| SR-418 | CF05-FR-029 | Decision record | `src/Domain/ExperimentService.php` | `decision` + `function recordDecision` present; real cross-file integration pending |
| SR-419 | CF05-FR-030 | Retention tiers | `src/Infrastructure/RetentionRunner.php` | `retention` + `function run` present; real cross-file integration pending |
| SR-420 | CF05-FR-031 | Deletion/anonymization propagation | `src/Domain/DeletionService.php` | `deletion` + `function runJob` present; real cross-file integration pending |
| SR-421 | CF05-FR-032 | Access projects and expiry | `src/Domain/AccessProjectService.php` | `expires_at` + `function expireDue` present; real cross-file integration pending |
| SR-422 | CF05-FR-033 | Query and export audit | `src/Infrastructure/AuditLogger.php` | `audit` + `function log` present; real cross-file integration pending |
| SR-423 | CF05-FR-034 | Provider/warehouse exit | `src/Domain/ProviderService.php` | `exit` + `function exitStatus` present; real cross-file integration pending |
| SR-424 | CF05-FR-035 | Restore and reproducibility | `src/Domain/RestoreService.php` | `restore` + `function verify` present; real cross-file integration pending |
| SR-425 | CROSS-01 | Central canonical ownership | `MANIFEST.json` | `non_goals` + `non_goals` present; real cross-file integration pending |
| SR-426 | CROSS-02 | File 16/17/18 and CF-01/02 sensitive exclusion | `src/Infrastructure/SensitiveValueDetector.php` | `message` + `password` present; real cross-file integration pending |
| SR-427 | CROSS-03 | Public traffic and ranking separation | `src/Infrastructure/IntegrationRegistry.php` | `owner` + `function register` present; real cross-file integration pending |
| SR-428 | CROSS-04 | Future40 separate approvals | `src/Infrastructure/FutureActivationService.php` | `activation` + `function approve` present; real cross-file integration pending |
| SR-429 | CROSS-05 | Schema, migrations, staging/live parity | `MANIFEST.json` | `live_deployed` + `staging_accepted` present; real cross-file integration pending |

## Two newly verified defects (not inherited from earlier rounds)

**CF05-D390-01 — HIGH/privacy:** A `QueryPrivacyGuard` request with 'tomorrow', an impossible UTC date, or an end preceding its start could succeed, whereas `PrivacyQueryPolicy::differencingRisk` does not evaluate malformed windows. Fix: strict canonical UTC SQL timestamp parsing and increasing bounds before all privacy checks, with fail-closed 503.

**CF05-D390-02 — HIGH/job consistency:** On duplicate `JobQueue::enqueue` `INSERT IGNORE`, a failed read-back or empty `job_uuid` was previously returned as successful duplicate. Fix: reject missing/invalid UUIDv4 and invalid job state with error code `smai_job_idempotency_conflict`.

**Before:** exact rc.16 source passed 2 of 8 new behavioral assertions. **After:** patched local source passed 8 of 8; no schema modification and no broad speculative patch stack. Source changes: `src/Domain/QueryPrivacyGuard.php`, `src/Infrastructure/JobQueue.php`. CI regression suite: `tests/sr390-429-privacy-job-integrity.php`. Public response contract version target `1.4.0`; database schema target `1.4.1` unchanged.

## Unresolved external evidence

No actual deployed WordPress files/checksums, DB/schema/options/migration or runtime logs have been verified. Real producer/consumer contracts for Files 00/05/16/17/18/19/20/23/24/25/26 and CF-01/02/03/06, staging user journeys, privacy field projection, security fuzzing, RTL/accessibility, backup/restore, approval, production rollout and postdeployment verification remain pending. Prior Cloudflare HTTP 530 gives connectivity evidence, not its exact origin cause.

**Repository HEAD:** to be updated from exact successful candidate CI. **Deployed Version:** unverified. **DB Version:** unverified. **Migration State:** unverified. **Live Verification Status:** not performed.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
