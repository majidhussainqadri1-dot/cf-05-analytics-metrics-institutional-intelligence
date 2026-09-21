# Review Round 15 — Access, Dashboards, Reports, Exports and Narratives

The full access/reporting surface was reviewed before this defect ledger was frozen and before corrections began.

## Confirmed defects
1. Access-project, dashboard and export code opened database transactions and then called `AuditLogger::log()`, which starts another transaction instead of appending to the caller transaction. The intended domain-write/audit atomicity was therefore not reliable.
2. Report creation/activation/control and narrative create/publish contained best-effort or ignored audit writes after state mutation; some report-dependent delivery revocations also ignored database failure.
3. Report delivery recipient references used plain SHA-256 over low-cardinality user IDs, enabling dictionary recovery of stored pseudonyms.
4. Report-delivery access changed delivery state and returned the bundle without requiring durable access-audit evidence.
5. ExportControlService still admitted the read-only `smai_audit` capability as a mutation authority internally even though the REST route had already been corrected.

## Corrections
Explicit caller transactions use `logInOpenTransaction`; core report/narrative mutations fail closed on audit failure; delivery recipient references/run keys are HMAC-keyed; delivery access requires durable audit evidence; delivery-revocation failures are surfaced; read-only auditor mutation authority was removed; permanent QA invariants were added.

No staging or live state is asserted by this source review.
