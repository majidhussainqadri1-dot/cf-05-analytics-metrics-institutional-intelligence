# CF-05 Three-Plan Harmonized Traceability — 1.0.0-rc.3

## Governing sources

1. `SSH-PMP-2026-v3.0` — Sabri Social Homeopathy Platform Definitive Integrated Master Plan v3.0.
2. `Consolidated All-Chats Recovered Directive Register 2.1` — all approved recovered directives through 5 August 2026.
3. `CF-05 — Analytics, Metrics and Institutional Intelligence — Conditional Complete Master Plan 2026 v1.0`.

Precedence remains: definitive Islamic rulings and ethics; the latest explicit Founder decision; the Definitive Master Plan; approved file-level contracts; then code and historical notes. CF-05 remains derivative and advisory. It never becomes the source of truth for identity, clinical records, money, publications, messages, search/recommendation ranking or moderation decisions.

## Harmonized implementation matrix

| Governing requirement | CF-05 implementation evidence | Acceptance boundary |
|---|---|---|
| One canonical owner; projections are never source of truth | `MANIFEST.json`, `IntegrationRegistry`, `docs/ARCHITECTURE.md`, owner/version fields throughout contracts | Native owner contracts must still be accepted in staging |
| Specified/Coded/Packaged/QA/Staging/Live/Operational are separate statuses | `MANIFEST.json`, release manifest, README, CI workflow | Hostinger staging and live evidence remain external gates |
| Disabled-by-default and evidence-bound activation | `RuntimeGate`, `RuntimeActivationService`, `SMAI_ACTIVATION_EVIDENCE_SHA256` | Founder-approved evidence and private keys required |
| Islamic privacy, dignity, minimization and no spying | allowlist ingestion, pseudonymization, short retention, cohort suppression, `QueryPrivacyGuard`, no raw clinical/message/payment/identity secrets | Islamic privacy review is an activation and periodic review gate |
| No unrestricted individual drill-down | `MetricDefinitionValidator`, dimension policies, prohibited combinations, `PrivacyQueryPolicy`, `QueryPrivacyGuard` | Real datasets require adversarial staging validation |
| Repeated-query/differencing resistance | privacy budget, distinct-slice budget, nested slice comparison, dimension-value hashing and audit | Thresholds require Founder/privacy-officer approval per metric |
| Green central identity, icons, responsive and accessible public presentation | `InsightsShortcode`, `assets/css/insights.css`, Dashicons, unique IDs, focus-visible and reduced-motion support | File 20/25 browser and visual-regression acceptance remains external |
| Separation of duties and independent approval | lifecycle actor checks plus least-privilege roles in `Activator` | Real named users and role assignments require staging evidence |
| Export revocation and secure lifecycle | `ExportService`, `ExportControlService`, encrypted payloads, token expiry, immediate revoke/purge | Storage/provider integration must be verified in staging |
| Scheduled report update, pause, resume, revoke and unsubscribe | `ReportService`, `ReportControlService`, `GovernanceRestController` | File 19 delivery adapter acceptance remains external |
| Fresh/adversarial review → correction → retest | `docs/REVIEW-ROUND-1.md` through review records, executable tests and cross-plan checks | Any new defect reopens the review gate |
| Staging first; backup/restore and rollback proof before live | `RestoreService`, `BackfillService`, rollback docs and CI package | No live claim before Hostinger rehearsal and Founder acceptance |

## CF-05 functional requirement coverage

`CF05-FR-001` through `CF05-FR-035` remain mapped in `docs/REQUIREMENTS-TRACEABILITY.md`. Release-candidate 1.0.0-rc.3 adds explicit completion controls for:

- dimension-specific sensitivity, cardinality and minimum-cohort policies;
- prohibited dimension combinations;
- repeated-query privacy budgets and differencing resistance;
- exact contract normalization for public metric quality states;
- explicit export revocation;
- report update/pause/resume/revoke/unsubscribe workflows;
- least-privilege operational roles;
- public UI class/ID/accessibility consistency;
- three-plan automated consistency checks.

## Truthful completion law

The repository may be source-complete and automated-QA green while `Staging-Accepted`, `Live-Deployed` and `Operational` remain false. Hostinger staging, actual Files 00/20/23/24/25/26 contracts, browser/accessibility/load/security testing, provider sandboxes, backup/restore, rollback rehearsal and Founder acceptance cannot be manufactured by source code and remain mandatory external release evidence.
