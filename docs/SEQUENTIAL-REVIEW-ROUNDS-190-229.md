# CF-05 — Fresh Sequential Source Reviews SR-190..SR-229

- **Audited immutable baseline:** `91f3164c608ca9ead2e7d75c2becde7b21c89b2c` (rc.11).
- **Governing plans:** CF-05 v1.1 Future40 Amended (2026-09-16), three central platform master plans, traffic analytics plan, and companion File/CF plans.
- **Method:** 40 discrete source-oriented requirement checks, verifying target implementations and PHP syntax; eight additional adversarial behavioral regressions reproduced FAIL on baseline and PASS on corrected source. A source-marker check is NOT an integration/acceptance test.
- **Change-control:** eight frozen source defects corrected as one governed rc.12 remediation cycle, with regression suite in `tests/fresh-completeness-regression.php`.

| Round | Governing requirement | Targeted subject | Evidence limitation |
|---:|---|---|---|
| SR-190 | CF05-FR-001 | Event schema registry | Source target present; runtime proof separate |
| SR-191 | CF05-FR-002 | Minimum event envelope | Source target present; runtime proof separate |
| SR-192 | CF05-FR-003 | Purpose/consent gate | Source target present; runtime proof separate |
| SR-193 | CF05-FR-004 | Field redaction/tokenization | Source target present; runtime proof separate |
| SR-194 | CF05-FR-005 | Dedupe/order/late events | Source target present; runtime proof separate |
| SR-195 | CF05-FR-006 | Quarantine and feedback | Source target present; runtime proof separate |
| SR-196 | CF05-FR-007 | Canonical derivative modeling | Source target present; runtime proof separate |
| SR-197 | CF05-FR-008 | Slowly changing definitions | Source target present; runtime proof separate |
| SR-198 | CF05-FR-009 | Data lineage | Source target present; runtime proof separate |
| SR-199 | CF05-FR-010 | Backfill/rebuild | Source target present; runtime proof separate |
| SR-200 | CF05-FR-011 | Data quality rules | Source target present; runtime proof separate |
| SR-201 | CF05-FR-012 | Region/provider control | Source target present; runtime proof separate |
| SR-202 | CF05-FR-013 | Metric definition | Source target present; runtime proof separate |
| SR-203 | CF05-FR-014 | Metric lifecycle | Source target present; runtime proof separate |
| SR-204 | CF05-FR-015 | Dimension allowlist | Source target present; runtime proof separate |
| SR-205 | CF05-FR-016 | Freshness and confidence | Source target present; runtime proof separate |
| SR-206 | CF05-FR-017 | Attribution and funnels | Source target present; runtime proof separate |
| SR-207 | CF05-FR-018 | Financial/clinical metric limits | Source target present; runtime proof separate |
| SR-208 | CF05-FR-019 | Role/project-scoped dashboards | Source target present; runtime proof separate |
| SR-209 | CF05-FR-020 | Metric presentation | Source target present; runtime proof separate |
| SR-210 | CF05-FR-021 | Drilldown safety | Source target present; runtime proof separate |
| SR-211 | CF05-FR-022 | Scheduled reports | Source target present; runtime proof separate |
| SR-212 | CF05-FR-023 | Secure exports | Source target present; runtime proof separate |
| SR-213 | CF05-FR-024 | Narrative insights | Source target present; runtime proof separate |
| SR-214 | CF05-FR-025 | Experiment registry | Source target present; runtime proof separate |
| SR-215 | CF05-FR-026 | Assignment separation | Source target present; runtime proof separate |
| SR-216 | CF05-FR-027 | Guardrail monitoring | Source target present; runtime proof separate |
| SR-217 | CF05-FR-028 | Statistical integrity | Source target present; runtime proof separate |
| SR-218 | CF05-FR-029 | Decision record | Source target present; runtime proof separate |
| SR-219 | CF05-FR-030 | Retention tiers | Source target present; runtime proof separate |
| SR-220 | CF05-FR-031 | Deletion/anonymization propagation | Source target present; runtime proof separate |
| SR-221 | CF05-FR-032 | Access projects and expiry | Source target present; runtime proof separate |
| SR-222 | CF05-FR-033 | Query and export audit | Source target present; runtime proof separate |
| SR-223 | CF05-FR-034 | Provider/warehouse exit | Source target present; runtime proof separate |
| SR-224 | CF05-FR-035 | Restore and reproducibility | Source target present; runtime proof separate |
| SR-225 | CROSS-01 | Single canonical owner, File 00/01/20/23/24/25/26 | External ownership/activation unverified |
| SR-226 | CROSS-02 | Sensitive CF-01/CF-03/File 17/18 contracts | External ownership/activation unverified |
| SR-227 | CROSS-03 | Public traffic and intelligence boundaries | External ownership/activation unverified |
| SR-228 | CROSS-04 | Future-40 separate approval | External ownership/activation unverified |
| SR-229 | CROSS-05 | Migration, staging and live reality | External ownership/activation unverified |

## Eight confirmed baseline defects and corrections

1. **High — Snapshot:** execution-time authorization absent; now rechecks `smai_manage_quality`.
2. **Medium — Source timestamps:** `TransformationEngine` formerly accepted `strtotime('tomorrow')`; now enforces strict ISO-8601 or stored canonical UTC.
3. **Medium — Funnel timestamps:** relative datetimes could affect rate; now rejected.
4. **Medium — Attribution tie:** conflicting equal-time first touches created row-order-dependent credit; now ambiguous subject excluded.
5. **High — Quality:** worker rechecks current `smai_manage_quality`.
6. **High — Backfill:** worker rechecks independent approver `smai_approve_catalog`.
7. **High — Export:** worker rechecks requester `smai_export_metrics`.
8. **High — Reports:** worker rechecks owner `smai_manage_reports`.

All eight adversarial cases failed on the original rc.11 source and passed in the amended local candidate. The committed regression file contains nine assertions including one positive compatibility check.

## Not verified, not resolved

- Exact deployed plugin/version/checksum, database/schema/rows/options/migration state and runtime logs.
- Real Files 00/01/20/23/24/25/26 plus CF-01/CF-03/File 17/18 contracts, producer-event/consent flows and nonduplication across owner boundaries.
- Staging acceptance, browser/RTL/a11y, performance/penetration, restore/rollback rehearsal, Founder approval, deployment and post-deploy retest.

Repository HEAD is determined by the final PR head and exact-head CI. **Deployed Version:** unverified. **DB Version:** unverified. **Migration State:** unverified. **Live Verification Status:** unverified.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
