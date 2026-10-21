# CF-05 — Fresh Forty Scoped Reviews SR-270..SR-309 (2026-10-21)

## Source and plan freeze
- **Exact baseline reviewed:** `daa2879c3978e9875a4be646defee8876fb1a1f2` — GitHub Actions candidate **1.0.0-rc.13** (not the production deployment).
- **Governing sources:** Founder CF-05 v1.1 Future40 Amended 2026-09-16; Three Central Governing Plans; separate Traffic Analytics/Disease Intelligence; Files 16/17/18 and CF-01/02/03/06 plans.
- **Method:** 40 distinct requirement-oriented source inspections (35 functional IDs and five ownership/privacy/release boundaries), baseline reproducibility and PHP negative/positive privacy-ledger fixtures. The source targets were present at all 40 checked places. The mere presence of a source target does not prove complete behavior, integration acceptance or live parity.
- **Current release:** `1.0.0-rc.14`, target DB schema `1.4.1`, target contracts `1.4.0`. No live or staging equivalence assertion.

| Review | Governing subject | Source path inspected | Assessment boundary |
|---|---|---|---|
| SR-270 | CF05-FR-001 — Event schema registry | `src/Domain/EventSchemaRegistry.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-271 | CF05-FR-002 — Minimum event envelope | `src/Contracts/EventSchemaValidator.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-272 | CF05-FR-003 — Purpose/consent gate | `src/Domain/EventIngestionService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-273 | CF05-FR-004 — Field redaction and tokenization | `src/Domain/PrivacyGateway.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-274 | CF05-FR-005 — Dedupe/ordering/late event handling | `src/Domain/EventIngestionService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-275 | CF05-FR-006 — Quarantine and feedback | `src/Domain/EventIngestionService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-276 | CF05-FR-007 — Canonical derivative dataset modeling | `src/Domain/DatasetCatalog.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-277 | CF05-FR-008 — Historical/slowly changing definitions | `src/Domain/PipelineService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-278 | CF05-FR-009 — Lineage and provenance | `src/Domain/LineageService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-279 | CF05-FR-010 — Backfill, shadow builds and rollback | `src/Domain/BackfillService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-280 | CF05-FR-011 — Data quality | `src/Domain/QualityService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-281 | CF05-FR-012 — Region/provider governance | `src/Domain/ProviderService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-282 | CF05-FR-013 — Metric definition | `src/Contracts/MetricDefinitionValidator.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-283 | CF05-FR-014 — Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-284 | CF05-FR-015 — Dimension/filter allowlist | `src/Domain/MetricQueryService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-285 | CF05-FR-016 — Freshness/quality caveats | `src/Domain/SnapshotDisclosurePolicy.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-286 | CF05-FR-017 — Funnel and attribution | `src/Domain/FunnelAttributionCalculator.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-287 | CF05-FR-018 — Clinical/finance-sensitive exclusion | `src/Infrastructure/SensitiveValueDetector.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-288 | CF05-FR-019 — Role/project dashboards | `src/Domain/DashboardService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-289 | CF05-FR-020 — Metric presentation | `src/Presentation/InsightsShortcode.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-290 | CF05-FR-021 — Differencing and privacy query budgets | `src/Domain/QueryPrivacyGuard.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-291 | CF05-FR-022 — Scheduled reports | `src/Domain/ReportService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-292 | CF05-FR-023 — Secure exports | `src/Domain/ExportService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-293 | CF05-FR-024 — Narratives/citation lineage | `src/Domain/NarrativeService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-294 | CF05-FR-025 — Experiment registry | `src/Domain/ExperimentService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-295 | CF05-FR-026 — Assignment authority separation | `src/Domain/ExperimentService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-296 | CF05-FR-027 — Experiment guardrails | `src/Domain/ExperimentService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-297 | CF05-FR-028 — Statistical uncertainty | `src/Domain/Statistics.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-298 | CF05-FR-029 — Human decision records | `src/Domain/ExperimentService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-299 | CF05-FR-030 — Retention tiers | `src/Infrastructure/RetentionRunner.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-300 | CF05-FR-031 — Deletion propagation | `src/Domain/DeletionService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-301 | CF05-FR-032 — Access projects, expiration | `src/Domain/AccessProjectService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-302 | CF05-FR-033 — Audit and minimized logs | `src/Infrastructure/AuditLogger.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-303 | CF05-FR-034 — Provider/warehouse exit | `src/Domain/ProviderService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-304 | CF05-FR-035 — Restore/reproduction/deletion floor | `src/Domain/RestoreService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-305 | CROSS-01 — Central canonical single-owner rules | `MANIFEST.json` | Source target confirmed; runtime/companion acceptance separate |
| SR-306 | CROSS-02 — Files 16/17/18 and CF-01/02/03 data separation | `MANIFEST.json` | Source target confirmed; runtime/companion acceptance separate |
| SR-307 | CROSS-03 — Public traffic / disease intelligence distinction | `src/Infrastructure/IntegrationRegistry.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-308 | CROSS-04 — Future-40 guarded feature activation | `src/Infrastructure/FutureActivationService.php` | Source target confirmed; runtime/companion acceptance separate |
| SR-309 | CROSS-05 — Schema, migration, exact deployment truth | `src/Infrastructure/RuntimeGate.php` | Source target confirmed; runtime/companion acceptance separate |

## New confirmed defects: 3

| ID | Severity | Reproduction on rc.13 | Correction on rc.14 |
|---|---|---|---|
| CF05-D270-01 | High — privacy | Database `get_results` failure produced no ledger array; accounting treated failure as no prior queries and permitted a result | Fail closed with HTTP 503 `smai_privacy_evidence_unavailable` when the ledger read fails or exceeds its 1000-row safety cap |
| CF05-D270-02 | High — privacy/integrity | Malformed historical `response_json` could deserialize to `[]` and silently disappear from differencing/budget analysis | Validate prior ledger fingerprint, cohort and cost before continuing; preserve supported legacy fingerprint format; reject corrupted evidence |
| CF05-D270-03 | High — audit/retention | A request repeated after its previous 1-hour window could collide with an existing unpurged idempotency key; no new current-hour witness was inserted | Bind privacy ledger idempotency key to a bounded hourly epoch, retaining project-scoped budget and rolling-hour query checks |

## Regression evidence and closure definition

- On immutable rc.13 baseline: three adversarial negative fixtures **failed**, while normal valid ledger insertion passed (1/4).
- Against corrected local rc.14 PHP: all four test cases passed (4/4).
- The four tests are permanently checked in `tests/sr270-309-privacy-ledger.php` and `scripts/qa.sh`; successful local tests do not substitute for exact-head CI.
- Security hardening remained restricted to `QueryPrivacyGuard.php`; no speculative deployment/migration fix, no change to domain canonical ownership.
- **Still unverified:** cross-file producer contracts and genuine consent/age/deletion integration, live registered WP plugin/source checksum, production DB/wp_options/migration state, provider routing, Hostinger-equivalent staging, performance and privacy penetration, full accessible RTL, backup/restore/rollback, Founder approval, live retest. Conditional Future40 remains disabled by default.
- **No production state conclusion:** Repository HEAD = check GitHub exact branch after final release build. Deployed Version = unverified. DB Version = unverified. Migration State = unverified. Live Verification Status = unverified.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
