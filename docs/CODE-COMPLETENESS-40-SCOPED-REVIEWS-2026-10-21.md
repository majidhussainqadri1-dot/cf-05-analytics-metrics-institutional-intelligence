# CF-05 — Forty Scoped Code-Completeness Review Ledger (2026-10-21)

## Evidence and scope

The 40 numbered rows are **40 distinct, requirement-focused code reviews**, not a claim of 40 complete end-to-end production audits. The baseline was PR #1 review branch HEAD `6f200ba220724e0331b25c97af558d1906b55b58`, release candidate `1.0.0-rc.10`, schema `1.4.1`, contract `1.4.0`. The exact baseline CI artifact `10649221714` was downloaded and independently SHA-256 checksum-verified; its 71 PHP files passed local PHP syntax checks. The governing references are the founder-provided CF-05 Future40 Amended 2026-09-16 Word plan, integrated central master plan and the accompanying public-traffic, File 16/17/18 and CF-01/02/03 plans.

The audit separates (a) presence of source implementation, (b) executable fixture evidence, (c) cross-file/staging acceptance and (d) the actual deployed runtime. Finding source markers proves neither behavioral completion nor a safe production deployment.

## Forty reviewed concerns

| Review | Plan/cross-file subject | Code/evidence inspected | Result |
|---|---|---|---|
| 01 / CF05-FR-001 | Event schema registry | EventSchemaRegistry, EventSchemaValidator | Source code present; full runtime acceptance unverified |
| 02 / CF05-FR-002 | Minimum event envelope | EventSchemaValidator, EventIngestionService | Source code present; full runtime acceptance unverified |
| 03 / CF05-FR-003 | Purpose and consent | EventIngestionService, PrivacyGateway | Source code present; full runtime acceptance unverified |
| 04 / CF05-FR-004 | Redaction and pseudonymization | PrivacyGateway, SensitiveValueDetector | Source code present; full runtime acceptance unverified |
| 05 / CF05-FR-005 | Dedupe, replay, late events | EventIngestionService, CheckpointService | Source code present; full runtime acceptance unverified |
| 06 / CF05-FR-006 | Quarantine and owner feedback | EventIngestionService, QualityService | Source code present; full runtime acceptance unverified |
| 07 / CF05-FR-007 | Derivative data model | DatasetCatalog, DatasetDefinitionValidator | Source code present; full runtime acceptance unverified |
| 08 / CF05-FR-008 | Historical semantics | DatasetDefinitionValidator, SnapshotService | Source code present; full runtime acceptance unverified |
| 09 / CF05-FR-009 | Lineage and provenance | LineageService, PipelineService | Source code present; full runtime acceptance unverified |
| 10 / CF05-FR-010 | Backfill / rollback | BackfillService | Source code present; full runtime acceptance unverified |
| 11 / CF05-FR-011 | Data quality and freshness | QualityService | Source code present; full runtime acceptance unverified |
| 12 / CF05-FR-012 | Region / provider privacy | DatasetCatalog, ProviderService | Source code present; full runtime acceptance unverified |
| 13 / CF05-FR-013 | Semantic metric definition | MetricDefinitionValidator | Source code present; full runtime acceptance unverified |
| 14 / CF05-FR-014 | Metric version lifecycle | CatalogLifecycleService | Source code present; full runtime acceptance unverified |
| 15 / CF05-FR-015 | Dimension allowlist | MetricDefinitionValidator, MetricQueryService | Source code present; full runtime acceptance unverified |
| 16 / CF05-FR-016 | Quality / confidence display | MetricQueryService, SnapshotService | Source code present; full runtime acceptance unverified |
| 17 / CF05-FR-017 | Funnel and attribution | FunnelAttributionCalculator, SnapshotService | Confirmed baseline implementation gap; bounded funnel source + fixtures added; staging pending |
| 18 / CF05-FR-018 | Clinical / financial exclusion | SensitiveValueDetector, PrivacyGateway | Source boundary present; real companion/staging/live parity unverified |
| 19 / CF05-FR-019 | Scoped dashboards | DashboardService, AccessProjectService | Source code present; full runtime acceptance unverified |
| 20 / CF05-FR-020 | Metric presentation | InsightsShortcode, MetricQueryService | Source code present; full runtime acceptance unverified |
| 21 / CF05-FR-021 | Differencing and small cohorts | PrivacyQueryPolicy, QueryPrivacyGuard | Source code present; full runtime acceptance unverified |
| 22 / CF05-FR-022 | Scheduled report lifecycle | ReportService, ReportControlService | Source code present; full runtime acceptance unverified |
| 23 / CF05-FR-023 | Encrypted bounded exports | ExportService, ExportControlService | Source code present; full runtime acceptance unverified |
| 24 / CF05-FR-024 | Narrative citation integrity | NarrativeService | Source code present; full runtime acceptance unverified |
| 25 / CF05-FR-025 | Experiment registry | ExperimentService, ExperimentDefinitionValidator | Source code present; full runtime acceptance unverified |
| 26 / CF05-FR-026 | Assignment owner separation | ExperimentService, IntegrationRegistry | Source code present; full runtime acceptance unverified |
| 27 / CF05-FR-027 | Experiment guardrails | ExperimentService | Source code present; full runtime acceptance unverified |
| 28 / CF05-FR-028 | Statistics and uncertainty | Statistics, ExperimentService | Source code present; full runtime acceptance unverified |
| 29 / CF05-FR-029 | Human decision records | ExperimentService | Source code present; full runtime acceptance unverified |
| 30 / CF05-FR-030 | Retention tiers | RetentionRunner | Source code present; full runtime acceptance unverified |
| 31 / CF05-FR-031 | Deletion propagation | DeletionService | Source code present; full runtime acceptance unverified |
| 32 / CF05-FR-032 | Access project expiry | AccessProjectService | Source code present; full runtime acceptance unverified |
| 33 / CF05-FR-033 | Query / export audit | MetricQueryService, AuditLogger | Source code present; full runtime acceptance unverified |
| 34 / CF05-FR-034 | Provider exit and reconciliation | ProviderService, RestoreService | Source code present; full runtime acceptance unverified |
| 35 / CF05-FR-035 | Restore and deletion floor | RestoreService | Source code present; full runtime acceptance unverified |
| 36 / Cross-01 | File 00/01/20/23/24/25/26 ownership | IntegrationRegistry, three-plan traceability | Source boundary present; real companion/staging/live parity unverified |
| 37 / Cross-02 | CF-01/CF-03/Files 17/18 sensitive-data separation | SensitiveValueDetector, companion boundaries | Source boundary present; real companion/staging/live parity unverified |
| 38 / Cross-03 | Public traffic and disease-intelligence boundary | MetricCatalog, governing public traffic master plan | Source boundary present; real companion/staging/live parity unverified |
| 39 / Cross-04 | Forty Future-40 feature gates | Future40Engine, FutureActivationService | Source code present; full runtime acceptance unverified |
| 40 / Cross-05 | Release, schema, migration, CI and Live-Parity gates | RuntimeGate, SchemaMigrator, exact-head Actions | Source boundary present; real companion/staging/live parity unverified |

## Frozen defect ledger before correction

| Defect | Root cause | Confirmed evidence | Code/document correction | Closure boundary |
|---|---|---|---|---|
| CF05-AUD-01 | FR-017 ordered funnel/attribution was not executable | Source calculated only count/sum/average/ratio; no `funnel` implementation | Introduced privacy-bounded subject-deduplicated funnel calculation, field-type contract, first/last/linear attribution, channel-aware snapshots and targeted fixtures | Local/CI source verification; staging data provenance and companion contracts still pending |
| CF05-AUD-02 | Repository FR-001..035 labels had drifted from the actual 2026-09-16 Word plan | 15 clearly misassigned IDs and two partially misaligned descriptions on manual ID-by-ID comparison | Replaced the complete 35-row traceability table with governing subject names and source owners | Documentation corrected; integration acceptance remains distinct |
| CF05-AUD-03 | CI architecture gate checked ID existence, not exact meaning | `scripts/architecture-check.py` previously searched only for `CF05-FR-###` | Added complete ID-to-subject parity assertion for all 35 governing IDs | Exact-head CI acceptance required |
| CF05-AUD-04 | Metric validator silently accepted unimplemented freeform rules | A syntactically valid metric with arbitrary `attribution` passed validation before correction | Unknown/non-executable freeform attribution, exclusions and rounding now fail closed; typed funnel contract validated | PHP regression fixtures + exact-head CI required |
| CF05-AUD-05 | Mutable PR prose can point to an earlier commit SHA | PR #1 previously named `db4721f` although actual baseline was `6f200ba` | Update PR text after final evidence commit | Mutable document, must be checked after every branch update |

## Confirmed checks and explicit blockers

- The 40 reviewed areas included all 35 CF-05 functional requirement subjects plus five cross-file and release-gate reviews. Initial marker scan: 36 source-marker matches and four investigation flags. Three flags were resolved by inspecting the owning classes (event envelope, lineage and sensitive exclusion). The fourth (`CF05-FR-017`) was a real executable gap.
- Existing automated CI on the baseline was green for PHP 8.1, 8.2 and 8.3, deterministic packaging, checksum and build manifest; any corrected HEAD requires its **own** exact-head CI.
- `CF05-FUT-001..040` have listed, gated handlers, but source presence does not prove live activation or safe provider operation. Their state remains disabled by default.
- Remaining **external acceptance blockers**: actual File 00/05/20/23/24/25/26 integrations; clinical/finance and File 17/18 event privacy contracts; Hostinger-equivalent staging with legitimate role journeys; database/schema/migration freeze; RTL/accessibility/load/penetration and backup/restore rehearsal; package-to-deployed checksum parity; Founder approval.
- No speculative live bug patch is justified: **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Mandatory final evidence slots

Repository HEAD: to be updated after code/report commits.
Deployed Version: unverified.
DB Version: unverified.
Migration State: unverified.
Live Verification Status: unverified; no resolution claim.
