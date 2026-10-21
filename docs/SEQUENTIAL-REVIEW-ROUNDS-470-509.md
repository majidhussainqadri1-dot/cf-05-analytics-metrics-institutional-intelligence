# CF-05 — New Forty-Review Source Completeness, Future-40 Privacy and Retry Integrity (SR-470–509)

## Governing basis and truth boundaries

- Supplied **CF-05 Master Plan v1.1, Future40 Amendment, 16 September 2026**; consolidated three central plans; separate public visitor Traffic Analytics / Disease Intelligence plans.
- Companion Files 00/05/16/17/18/19/20/23/24/25/26 and conditional CF-01/02/03/06 retain their own owner/role/consent/deletion/visual contracts. The exact companion **installed** contracts and end-to-end staged interoperability are still unverified.
- Frozen PR development baseline prior to this cycle: `0f5c15192e3f3c9503c07b7edc52c9b4989fce6b` (`1.0.0-rc.18`); `main` independently remains a different reality; PR #1 is draft.
- Source control check does **not** establish completeness of user workflows, external provider operations, DB migrations, or installed code. Separate source, test, package, staging and live claims.

## Forty new requirement-bound source reviews

The executable `scripts/sr470-509-source-integrity-check.py` independently revisits each of the 35 governing FR subjects and five cross-plan/release safeguards; it demands the right file, two requirement-specific witnesses, structurally present PHP code where applicable, and independent exact source safety/release gates. This is a **second, separate 40-item pass**, not a claim of forty exhaustive security or E2E audits.

| Round | Governing requirement | Verification subject | Owning source | Audit evidence level |
|---|---|---|---|---|
| SR-470 | CF05-FR-001 | Event schema registry | `src/Domain/EventSchemaRegistry.php` | Source witness + PHP validity + exact-identity gate |
| SR-471 | CF05-FR-002 | Minimum event envelope | `src/Domain/EventIngestionService.php` | Source witness + PHP validity + exact-identity gate |
| SR-472 | CF05-FR-003 | Purpose/consent gate | `src/Domain/EventIngestionService.php` | Source witness + PHP validity + exact-identity gate |
| SR-473 | CF05-FR-004 | Field redaction/tokenization | `src/Domain/PrivacyGateway.php` | Source witness + PHP validity + exact-identity gate |
| SR-474 | CF05-FR-005 | Dedupe/order/late events | `src/Domain/EventIngestionService.php` | Source witness + PHP validity + exact-identity gate |
| SR-475 | CF05-FR-006 | Quarantine and feedback | `src/Domain/EventIngestionService.php` | Source witness + PHP validity + exact-identity gate |
| SR-476 | CF05-FR-007 | Canonical derivative modeling | `src/Domain/DatasetCatalog.php` | Source witness + PHP validity + exact-identity gate |
| SR-477 | CF05-FR-008 | Slowly changing definitions | `src/Contracts/DatasetDefinitionValidator.php` | Source witness + PHP validity + exact-identity gate |
| SR-478 | CF05-FR-009 | Data lineage | `src/Domain/LineageService.php` | Source witness + PHP validity + exact-identity gate |
| SR-479 | CF05-FR-010 | Backfill/rebuild | `src/Domain/BackfillService.php` | Source witness + PHP validity + exact-identity gate |
| SR-480 | CF05-FR-011 | Data quality rules | `src/Domain/QualityService.php` | Source witness + PHP validity + exact-identity gate |
| SR-481 | CF05-FR-012 | Region/provider control | `src/Domain/ProviderService.php` | Source witness + PHP validity + exact-identity gate |
| SR-482 | CF05-FR-013 | Metric definition | `src/Contracts/MetricDefinitionValidator.php` | Source witness + PHP validity + exact-identity gate |
| SR-483 | CF05-FR-014 | Metric lifecycle | `src/Domain/CatalogLifecycleService.php` | Source witness + PHP validity + exact-identity gate |
| SR-484 | CF05-FR-015 | Dimension allowlist | `src/Domain/MetricQueryService.php` | Source witness + PHP validity + exact-identity gate |
| SR-485 | CF05-FR-016 | Freshness and confidence | `src/Domain/SnapshotDisclosurePolicy.php` | Source witness + PHP validity + exact-identity gate |
| SR-486 | CF05-FR-017 | Attribution and funnels | `src/Domain/FunnelAttributionCalculator.php` | Source witness + PHP validity + exact-identity gate |
| SR-487 | CF05-FR-018 | Financial/clinical metric limits | `src/Infrastructure/SensitiveValueDetector.php` | Source witness + PHP validity + exact-identity gate |
| SR-488 | CF05-FR-019 | Role/project-scoped dashboards | `src/Domain/DashboardService.php` | Source witness + PHP validity + exact-identity gate |
| SR-489 | CF05-FR-020 | Metric presentation | `src/Presentation/InsightsShortcode.php` | Source witness + PHP validity + exact-identity gate |
| SR-490 | CF05-FR-021 | Drilldown safety | `src/Domain/QueryPrivacyGuard.php` | Source witness + PHP validity + exact-identity gate |
| SR-491 | CF05-FR-022 | Scheduled reports | `src/Domain/ReportService.php` | Source witness + PHP validity + exact-identity gate |
| SR-492 | CF05-FR-023 | Secure exports | `src/Domain/ExportService.php` | Source witness + PHP validity + exact-identity gate |
| SR-493 | CF05-FR-024 | Narrative insights | `src/Domain/NarrativeService.php` | Source witness + PHP validity + exact-identity gate |
| SR-494 | CF05-FR-025 | Experiment registry | `src/Domain/ExperimentService.php` | Source witness + PHP validity + exact-identity gate |
| SR-495 | CF05-FR-026 | Assignment separation | `src/Domain/ExperimentService.php` | Source witness + PHP validity + exact-identity gate |
| SR-496 | CF05-FR-027 | Guardrail monitoring | `src/Domain/ExperimentService.php` | Source witness + PHP validity + exact-identity gate |
| SR-497 | CF05-FR-028 | Statistical integrity | `src/Domain/Statistics.php` | Source witness + PHP validity + exact-identity gate |
| SR-498 | CF05-FR-029 | Decision record | `src/Domain/ExperimentService.php` | Source witness + PHP validity + exact-identity gate |
| SR-499 | CF05-FR-030 | Retention tiers | `src/Infrastructure/RetentionRunner.php` | Source witness + PHP validity + exact-identity gate |
| SR-500 | CF05-FR-031 | Deletion/anonymization propagation | `src/Domain/DeletionService.php` | Source witness + PHP validity + exact-identity gate |
| SR-501 | CF05-FR-032 | Access projects and expiry | `src/Domain/AccessProjectService.php` | Source witness + PHP validity + exact-identity gate |
| SR-502 | CF05-FR-033 | Query and export audit | `src/Infrastructure/AuditLogger.php` | Source witness + PHP validity + exact-identity gate |
| SR-503 | CF05-FR-034 | Provider/warehouse exit | `src/Domain/ProviderService.php` | Source witness + PHP validity + exact-identity gate |
| SR-504 | CF05-FR-035 | Restore and reproducibility | `src/Domain/RestoreService.php` | Source witness + PHP validity + exact-identity gate |
| SR-505 | CROSS-1 | Central owner/derivative boundary | `MANIFEST.json` | Source witness + PHP validity + exact-identity gate |
| SR-506 | CROSS-2 | AI, communication and marketplace event separation | `src/Infrastructure/SensitiveValueDetector.php` | Source witness + PHP validity + exact-identity gate |
| SR-507 | CROSS-3 | Clinical/support/localization ownership boundaries | `src/Infrastructure/IntegrationRegistry.php` | Source witness + PHP validity + exact-identity gate |
| SR-508 | CROSS-4 | Future-40 separate approval/activation | `src/Infrastructure/FutureActivationService.php` | Source witness + PHP validity + exact-identity gate |
| SR-509 | CROSS-5 | Schema/migration/staging/live parity | `MANIFEST.json` | Source witness + PHP validity + exact-identity gate |

## Forty separately executed adversarial safety fixtures

`tests/sr470-509-future40-privacy.php` runs all `CF05-FUT-001..040` handlers independently with forbidden nested private-key evidence and asserts controlled rejection. **40/40 PHP fixtures passed** in exact-head GitHub CI. These deliberately test the cross-cutting privacy gate, not every individual feature's business function.

## One newly confirmed coding defect — no speculative patch stacking

**CF05-D470-01 / job idempotency retry-budget integrity**:

- Root cause: `JobQueue::enqueue()` previously read duplicate rows with only `job_uuid,state,job_type,payload_json`, omitting the immutable stored `max_attempts`. Reusing the same key, job type and payload with a conflicting requested maximum retry count silently returned `duplicate:true` for an incompatible request. Malformed/missing retry metadata was likewise silently accepted.
- Red evidence: mandatory new `tests/sr470-509-job-immutability.php` on pre-fix commit `f080ac48876e8221ca6a1e5faa71d814e848885e`: **4/8 passed; 4 failed** on exact-head Actions runs `39663377119` and `39663368951`.
- Source fix: SELECT includes `max_attempts`; require canonical integer range 1..20; compare to bounded requested retry limit; reject any absent, corrupt or mismatching value as `smai_job_idempotency_payload_conflict` HTTP 409. Preserve previous job-type/payload idempotency guards. Older SR390–429 / SR430–469 fixtures were updated to accurately mock an immutable job budget.
- Green evidence: `8/8` adversarial fixtures, `40/40` source probes, `40/40` Future-40 nested private-data negatives, existing privacy and functional tests, PHP 8.1/8.2/8.3 QA, packaging, and cross-plan checks passed on subsequent exact development HEAD.
- The correction is in the **development PR**, not a verified deployed WordPress plugin. No live defect was inferred.

## Remaining release/operational gates

1. WPVibe site connection to `sabrihomeopathy.com` returned **Cloudflare HTTP 530** on direct/available relay path; this observation does not establish origin, DNS, bot control, WAF or WordPress as root cause. No security control disabled.
2. Obtain independently authenticated deployed plugin version, installed files/package checksum, live WordPress options, exact DB tables/columns/schema version and migration/error logs before any live-cause diagnosis.
3. Verify native owner event/consent/minor/deletion and reader contracts with Files 00/05/16/17/18/19/20/23/24/25/26 and CF-01/02/03/06 on staging. Merely registering filters or hooks is insufficient.
4. Independent warehouse/region/privacy/provider review, Data Governance Council and Founder activation authorization, real user roles, mobile/RTL/accessibility, security/penetration/load, backup restore, provider-failure and rollback rehearsal.
5. Founder-controlled merge, package deploy, monitored live smoke/re-test and deployed-vs-repository parity must occur before calling any live incident resolved.

## Explicit reality ledger

- Repository `main`: previous confirmed `3dd37bed22c86403b007653cff53ffbe8c1a0225` (recheck at release).
- Development HEAD: exact commit and CI run must be freshly read **after** this evidence document is committed; do not cite a stale earlier green run.
- Target/source schema: `1.4.1`; target public contract: `1.4.0`.
- Deployed Version: **UNVERIFIED**.
- Live DB Version: **UNVERIFIED**.
- Migration State: **UNVERIFIED**.
- Live Verification Status: **NOT VERIFIED**.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
