# Forty Fresh Review-and-Fix Rounds — CF-05 1.0.0-rc.4

Each round was completed in sequence; the discovered defect was corrected before the next round began.

| ID | Review focus | Defect corrected |
|---|---|---|
| REV-07 | Event envelope contract | Missing provider/region/guardian metadata and strict additional-property control |
| REV-08 | Event schema registry | Weak top-level, field, consumer and policy validation |
| REV-09 | Privacy gateway | Undeclared/required field, consent, guardian and reference validation gaps |
| REV-10 | Sensitive data detection | Nested credential, identity, token and payment patterns incomplete |
| REV-11 | Service authentication | Weak signature envelope, replay and failed-auth throttling |
| REV-12 | Runtime activation | Non-atomic proposal/approval evidence transition |
| REV-13 | Runtime state | Catalog/environment/evidence compatibility incomplete |
| REV-14 | Activation operations | Schedules and capabilities could drift after upgrade |
| REV-15 | Event ordering | Source sequence lacked one canonical collision key |
| REV-16 | Event ingestion | Transaction, duplicate/collision, correction and late-event handling gaps |
| REV-17 | Jobs and repair | Lease recovery and retry safety incomplete |
| REV-18 | Audit verification | Canonical context, pagination and head verification defects |
| REV-19 | Metric definition | Missing dimension privacy/cardinality/combinations and strict calculation closure |
| REV-20 | Metric catalog | Source dataset/privacy compatibility and transactional audit gaps |
| REV-21 | Static privacy policy | Weak prohibited-combination, cost and differencing rules |
| REV-22 | Query privacy state | Non-HMAC fingerprints and weak serialization/fail-closed behavior |
| REV-23 | Metric query | Staleness, exact authorization, cohort and audit gaps |
| REV-24 | Snapshot publication | Non-atomic revision, quality normalization and privacy checks |
| REV-25 | Dataset catalog | Source ownership, field typing, retention and privacy closure gaps |
| REV-26 | Transformation/filter | Type coercion and unsafe comparisons |
| REV-27 | Pipeline processing | Retry/idempotency and event-to-row atomicity gaps |
| REV-28 | Checkpoints | Concurrent watermark regression risk |
| REV-29 | Lineage | Duplicate/invalid edges and weak evidence validation |
| REV-30 | Data quality | Incomplete rule normalization, bounded execution and result evidence |
| REV-31 | Backfill | Build source selection, expiry, transaction and audit gaps |
| REV-32 | Access projects | Stale contracts, overbroad fields, dependent revocation and transaction gaps |
| REV-33 | Dashboards | Audience/privacy reauthorization, audit and UI policy gaps |
| REV-34 | Reports | Definition/recipient/hash/audit and output-contract gaps |
| REV-35 | Report controls | Non-atomic lifecycle, weak unsubscribe token and material-change reapproval gaps |
| REV-36 | Exports | Missing live authorization, scoped encryption and explicit payload purge |
| REV-37 | Narratives | Citations not bound strongly to immutable snapshot evidence |
| REV-38 | Experiments | Weak protocol, duplicate variants, assignment and analysis governance |
| REV-39 | Providers | Approver identity loss and incomplete verified-exit evidence |
| REV-40 | Deletion | Overbroad aggregate invalidation and missing experiment deletion key |
| REV-41 | Restore | Deletion/access/provider floors and resurrection checks incomplete |
| REV-42 | Shared infrastructure | Nested audit transactions, rate limiter and idempotency durability defects |
| REV-43 | REST/API security | Payload idempotency, error leakage and raw download controls incomplete |
| REV-44 | Operations/UI | Health false-green, unbounded retention, repair redirect, cache/error/accessibility and uninstall gaps |
| REV-45 | Migration/contracts | Missing event metadata columns, assignment deletion/index migration and release contract drift |
| REV-46 | Final adversarial release review | Expanded executable tests, architecture/security scans, deterministic package, full documentation and exact-head CI gate |

## Result

Forty requested source-review rounds are documented. This register records source corrections; it does not substitute for Hostinger staging, real companion contracts, browsers, assistive technology, load/penetration tests, backup/restore or Founder production approval.
