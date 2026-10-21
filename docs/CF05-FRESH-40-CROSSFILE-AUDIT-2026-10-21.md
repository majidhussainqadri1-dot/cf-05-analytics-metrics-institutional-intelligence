# CF-05 — Fresh Forty-Round Plan, Privacy and Timestamp Audit — 21 Oct 2026

## Source truth and exact frozen baseline
- Actual `main` source HEAD: `3dd37bed22c86403b007653cff53ffbe8c1a0225` (README-only).
- Candidate base before this review: PR #1 `cab055d3e04bea5aaac913d747dc9a038a9e22ca` — `1.0.0-rc.12`.
- New integrated branch: `audit/cf05-rc12-40-review-safety-parity-20261021`; **not main, not staging, not Live**.
- Governing uploaded files: CF-05 Conditional Complete Master Plan v1.1 Future40 (16 Sep 2026); Three Central Plans Consolidated Governing Master; Traffic Analytics and Disease Intelligence; and File16/17/18 plus CF01/CF02/CF03 contracts. The CF-05 plan itself specifies FR-001..035 and separately gated FUT-001..040.
- Evidence levels are separated: code/source map, synthetic executable regression, native-owner integration contract, actual deployed WordPress/DB/migrations, Founder authorization. Success in one does not prove another.

## Forty plan-to-code control points
Each check below is independently identified. This is a source assertion against planned ownership and approved interfaces, **not** a substitute for behavioral, integration, staging, security or Live tests.

| Round | Governing requirement | Source concern | Test evidence |
|---:|---|---|---|
| 01 | CF05-FR-001 | Event-schema owner boundaries | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 02 | CF05-FR-002 | Minimum event envelope | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 03 | CF05-FR-003 | Purpose/consent assertions | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 04 | CF05-FR-004 | PII/token redaction | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 05 | CF05-FR-005 | Dedupe/replay/late events | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 06 | CF05-FR-006 | Safe quarantine/owner feedback | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 07 | CF05-FR-007 | Derivative model ownership | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 08 | CF05-FR-008 | Historical definition semantics | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 09 | CF05-FR-009 | Lineage/provenance chain | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 10 | CF05-FR-010 | Backfill and shadow rebuild | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 11 | CF05-FR-011 | Data quality / freshness | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 12 | CF05-FR-012 | Provider and region restrictions | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 13 | CF05-FR-013 | Metric semantic definition | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 14 | CF05-FR-014 | Metric lifecycle and versions | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 15 | CF05-FR-015 | Dimension allowlist | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 16 | CF05-FR-016 | Freshness/confidence indication | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 17 | CF05-FR-017 | Funnel and attribution | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 18 | CF05-FR-018 | Clinical/financial minimization | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 19 | CF05-FR-019 | Dashboards per approved project | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 20 | CF05-FR-020 | Metric presentation | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 21 | CF05-FR-021 | Small cohort/differencing guard | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 22 | CF05-FR-022 | Scheduled reports | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 23 | CF05-FR-023 | Secure export and CSV handling | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 24 | CF05-FR-024 | Narrative evidence integrity | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 25 | CF05-FR-025 | Experiment registry | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 26 | CF05-FR-026 | Assignment separation | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 27 | CF05-FR-027 | Guardrail stop behavior | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 28 | CF05-FR-028 | Statistical uncertainty | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 29 | CF05-FR-029 | Human sign-off decisions | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 30 | CF05-FR-030 | Tiered retention | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 31 | CF05-FR-031 | Deletion propagation | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 32 | CF05-FR-032 | Access project expiry | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 33 | CF05-FR-033 | Query/export audit | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 34 | CF05-FR-034 | Provider/warehouse exit | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 35 | CF05-FR-035 | Restore/reproducibility | `scripts/fresh40-plan-parity-20261021.py` source-owner anchor |
| 36 | CEN-CROSS-1 | Cross-file owner/File00/20/23/24/25/26 | `scripts/fresh40-plan-parity-20261021.py` governance boundary |
| 37 | CEN-CROSS-2 | Cross-file sensitive CF01/CF03/File17/18 | `scripts/fresh40-plan-parity-20261021.py` governance boundary |
| 38 | CEN-CROSS-3 | Public visitor traffic/SEO boundary | `scripts/fresh40-plan-parity-20261021.py` governance boundary |
| 39 | CEN-CROSS-4 | Future40 independent activation | `scripts/fresh40-plan-parity-20261021.py` governance boundary |
| 40 | CEN-CROSS-5 | Exact deployed parity/release control | `scripts/fresh40-plan-parity-20261021.py` governance boundary |

## Forty executable negative/positive regressions

| Family | Scenarios | Files | Coverage |
|---|---:|---|---|
| Limit / quarantine | 20 | `tests/fresh-privacy-rate-boundary-20261021.php` | SQL unavailable read, zero/noninteger/overflow/fail-closed, bounded identifiers/PII metadata and quarantine source assertions |
| Time and reproducibility | 20 | `tests/fresh-four-parser-parity-20261021.php` | Five common positive/negative timestamp cases in each of four owners: event ingestion, derivative transformation, funnel, snapshot |
| **Total** | **40** | Both mandatory in `scripts/qa.sh` | Synthetic source/runtime tests, not Live journeys |

## Frozen source defects reproduced and bounded corrections

| Finding | Frozen rc.12 defect | Source fix | Safety evidence |
|---|---|---|---|
| CF05-2026-40-A | `RateLimiter` cast SQL NULL/error to zero and could allow requests after a failed read | Fail closed for unavailable, zero, invalid/noncanonical and overflow counts | 12 distinct SQL limit scenarios |
| CF05-2026-40-B | `EventIngestionService::quarantine` stored untrusted attacker-controlled JSON keys and invalid metadata that could include private clinical or identifier strings | Count-only quarantine metadata; safe identifier syntax; redacted free-text and purpose/trace on rejection | Eight minimized evidence/source checks; real data privacy audit still pending |
| CF05-2026-40-C | Source event-time regular expressions used `$` without absolute-end mode; PHP matches immediately before final newline, so uncanonical timestamps can pass; this affects up to four downstream consumers | `/D` strict pattern termination across EventIngestion, TransformationEngine, FunnelAttributionCalculator and SnapshotService, while preserving the explicitly supported canonical SQL-UTC derivative timestamp contract | 20 behavior checks and existing rc.12 date/attribution fixtures |

The source candidate already contained rc.12 repairs to FR017 funnel attribution ties, worker execution-time authorization, valid input timestamp handling, and other eight documented defects; this review **preserves** these rather than re-applying older rc.11 code.

## Remaining independent blockers (not repository bugs by default)

1. Required Founder decision that conditional CF-05 meets extraction activation conditions; signed Data Governance Council/privacy/security owners.
2. Exact-version native-owner producer/consumer event/consent/deletion contracts (File00/02/05/17/19/20/23/24/25/26 and conditional CF01/02/03), without becoming another canonical traffic counter or ranking authority.
3. Independent approved regional warehouse/data provider, encryption, recovery/legal/medical and PII/clinical controls.
4. WordPress staging install/upgrade, real DB tables/schema/options/migration + backup restore/rollback and retention/deletion evidence.
5. External privacy/security, load, browser, RTL/accessibility and provider-failure validations.
6. Founder exact-package Production approval, controlled rollout, deployment checksum and Live re-test.

## Release and Live Truth Ledger
- Final corrected PR HEAD / GitHub Actions run: **derive from final completed run only**, never from earlier green CI.
- Repository `main` HEAD: `3dd37bed22c86403b007653cff53ffbe8c1a0225`.
- Deployed Version: **UNVERIFIED**; DB Version: **UNVERIFIED**; Migration State: **UNVERIFIED**; Live Verification Status: **NOT PERFORMED**.
- No live symptom/root cause is diagnosed from PR metadata; no real patient/payment/identity content is used.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
