# CF-05 — Forty New Source-to-Plan Reviews SR-350…389 (21 October 2026)

## Provenance and scope

**Baseline exact HEAD:** `d8f4e1ef94d93450e1a6a6608c352c2d48a10318`, WordPress plugin `1.0.0-rc.15`. The controlling material is the CF-05 v1.1 Future40 Amended plan, the consolidated Three Central Governing Plans, the separate public Traffic Analytics/Disease Intelligence plan, and related Files 16/17/18 and CF-01/02/06 plans.

This cycle examines **35 governed requirements plus 5 cross-module/production-truth boundaries**. Forty source targets are present, but file/method presence is not a guarantee of executable completeness. Beyond the 40 scoped source probes, independently reproducible tests identified two sensitive data disclosure paths, both bounded by a single root cause: audience/recipient identity was treated as stronger authority than an approved recipient-specific analytics Access Project.

| Round | Plan subject | Inspected code location | Evidence level |
|---|---|---|---|
| SR-350 | CF05-FR-001 — Event schema registry | `src/Domain/EventSchemaRegistry.php` | Static source/method review, runtime not certified |
| SR-351 | CF05-FR-002 — Minimum event envelope | `src/Domain/EventIngestionService.php` | Static source/method review, runtime not certified |
| SR-352 | CF05-FR-003 — Purpose/consent gate | `src/Domain/EventIngestionService.php` | Static source/method review, runtime not certified |
| SR-353 | CF05-FR-004 — Field redaction/tokenization | `src/Domain/PrivacyGateway.php` | Static source/method review, runtime not certified |
| SR-354 | CF05-FR-005 — Dedupe/order/late events | `src/Domain/EventIngestionService.php` | Static source/method review, runtime not certified |
| SR-355 | CF05-FR-006 — Quarantine and feedback | `src/Domain/EventIngestionService.php` | Static source/method review, runtime not certified |
| SR-356 | CF05-FR-007 — Canonical derivative modeling | `src/Domain/DatasetCatalog.php` | Static source/method review, runtime not certified |
| SR-357 | CF05-FR-008 — Slowly changing definitions | `src/Contracts/DatasetDefinitionValidator.php` | Static source/method review, runtime not certified |
| SR-358 | CF05-FR-009 — Data lineage | `src/Domain/LineageService.php` | Static source/method review, runtime not certified |
| SR-359 | CF05-FR-010 — Backfill/rebuild | `src/Domain/BackfillService.php` | Static source/method review, runtime not certified |
| SR-360 | CF05-FR-011 — Data quality rules | `src/Domain/QualityService.php` | Static source/method review, runtime not certified |
| SR-361 | CF05-FR-012 — Region/provider control | `src/Domain/ProviderService.php` | Static source/method review, runtime not certified |
| SR-362 | CF05-FR-013 — Metric definition | `src/Contracts/MetricDefinitionValidator.php` | Static source/method review, runtime not certified |
| SR-363 | CF05-FR-014 — Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | Static source/method review, runtime not certified |
| SR-364 | CF05-FR-015 — Dimension allowlist | `src/Domain/MetricQueryService.php` | Static source/method review, runtime not certified |
| SR-365 | CF05-FR-016 — Freshness and confidence | `src/Domain/SnapshotDisclosurePolicy.php` | Static source/method review, runtime not certified |
| SR-366 | CF05-FR-017 — Attribution and funnels | `src/Domain/FunnelAttributionCalculator.php` | Static source/method review, runtime not certified |
| SR-367 | CF05-FR-018 — Financial/clinical metric limits | `src/Infrastructure/SensitiveValueDetector.php` | Static source/method review, runtime not certified |
| SR-368 | CF05-FR-019 — Role/project-scoped dashboards | `src/Domain/DashboardService.php` | Static source/method review, runtime not certified |
| SR-369 | CF05-FR-020 — Metric presentation | `src/Presentation/InsightsShortcode.php` | Static source/method review, runtime not certified |
| SR-370 | CF05-FR-021 — Drilldown safety | `src/Domain/QueryPrivacyGuard.php` | Static source/method review, runtime not certified |
| SR-371 | CF05-FR-022 — Scheduled reports | `src/Domain/ReportService.php` | Static source/method review, runtime not certified |
| SR-372 | CF05-FR-023 — Secure exports | `src/Domain/ExportService.php` | Static source/method review, runtime not certified |
| SR-373 | CF05-FR-024 — Narrative insights | `src/Domain/NarrativeService.php` | Static source/method review, runtime not certified |
| SR-374 | CF05-FR-025 — Experiment registry | `src/Domain/ExperimentService.php` | Static source/method review, runtime not certified |
| SR-375 | CF05-FR-026 — Assignment separation | `src/Domain/ExperimentService.php` | Static source/method review, runtime not certified |
| SR-376 | CF05-FR-027 — Guardrail monitoring | `src/Domain/ExperimentService.php` | Static source/method review, runtime not certified |
| SR-377 | CF05-FR-028 — Statistical integrity | `src/Domain/Statistics.php` | Static source/method review, runtime not certified |
| SR-378 | CF05-FR-029 — Decision record | `src/Domain/ExperimentService.php` | Static source/method review, runtime not certified |
| SR-379 | CF05-FR-030 — Retention tiers | `src/Infrastructure/RetentionRunner.php` | Static source/method review, runtime not certified |
| SR-380 | CF05-FR-031 — Deletion/anonymization propagation | `src/Domain/DeletionService.php` | Static source/method review, runtime not certified |
| SR-381 | CF05-FR-032 — Access projects and expiry | `src/Domain/AccessProjectService.php` | Static source/method review, runtime not certified |
| SR-382 | CF05-FR-033 — Query and export audit | `src/Infrastructure/AuditLogger.php` | Static source/method review, runtime not certified |
| SR-383 | CF05-FR-034 — Provider/warehouse exit | `src/Domain/ProviderService.php` | Static source/method review, runtime not certified |
| SR-384 | CF05-FR-035 — Restore and reproducibility | `src/Domain/RestoreService.php` | Static source/method review, runtime not certified |
| SR-385 | CROSS-01 — Central canonical ownership | `MANIFEST.json` | Static source/method review, runtime not certified |
| SR-386 | CROSS-02 — File 16/17/18 and CF-01/02 sensitive exclusion | `src/Infrastructure/SensitiveValueDetector.php` | Static source/method review, runtime not certified |
| SR-387 | CROSS-03 — Public traffic and ranking separation | `src/Infrastructure/IntegrationRegistry.php` | Static source/method review, runtime not certified |
| SR-388 | CROSS-04 — Future40 separate approvals | `src/Infrastructure/FutureActivationService.php` | Static source/method review, runtime not certified |
| SR-389 | CROSS-05 — Schema, migrations, staging/live parity | `MANIFEST.json` | Static source/method review, runtime not certified |

## Frozen defects and remediation

1. **D350-01 · High/Confidentiality:** Dashboard bundle checked the actual viewer against the Dashboard audience, but verified metric Access Projects by passing `dashboard.owner_user_id`, permitting audience recipients without their own governed Metric/Purpose grant. **Fix:** `AccessProjectService::authorizesViewer` now requires the viewer's own active/approved same-purpose, same-version metric access project; Dashboard's pre-disclosure guard and fetch pass the actual viewer. Unauthorized audience entries receive 403.
2. **D350-02 · High/Confidentiality:** An expiring recipient delivery link + insights capability could retrieve a report after the recipient's independently granted Metric access was absent/revoked. **Fix:** `ReportService::accessDelivery` re-evaluates every stored metric against the recipient's current approved analytics Access Project before the transaction/download. Unauthorized recipients receive 403.
3. **D350-03 · Medium/Governance:** Scheduled reports were permitted to create delivery links for recipients lacking independent metric grants, resulting in unsafe/unusable links. **Fix:** Report creation and activation verify every recipient/metric grant; queued work rechecks grants immediately before emitting each delivery event. Revoked recipients are excluded.

**Negative evidence:** The two external-data disclosures were reproduced using the exact rc.15 packaged PHP; both negative tests failed before the patch. **Positive/negative evidence:** The corrected implementation passed six focused PHP fixtures: denial without grant; allowance with independent grant; both Dashboard and Report flows. The six executable tests are in `tests/sr350-389-viewer-access-contract.php` and mandatory `scripts/qa.sh`; static source regression `scripts/sr350-389-plan-scope-check.py` protects forty plan rows plus the new access boundaries.

No live code was inspected or modified. Conditional runtime/Future40 remains disabled. The approved `schema_version=1.4.1` and `contract_version=1.4.0` are unchanged. No deployment, migration or schema takeover was attempted.

## External gates still open

Exact plugin/source checksum deployed on WordPress; live DB/wp_options/schema/migrations and runtime log; real File 00/05/16/17/18/19/20/23/24/25/26, CF-01/02/03/06 producer and service contracts; actual recipient's approved project-ownership cases in staging; performance with many recipients, cancellation, restoration, concurrency, TTL and adverse privacy/differencing; security/RTL/a11y/load; backup/restore/rollback; Founder approval; controlled deployment and postdeploy retest.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

**Required live-state status:** Repository HEAD — inspect final exact CI commit; Deployed Version — unverified; DB Version — unverified; Migration State — unverified; Live Verification Status — unverified.
