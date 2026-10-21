# CF-05 Review Round 10 — Final Adversarial Release Governance

The entire round was audited before any correction was started. This ledger was frozen first, then all defects were corrected together.

## Defects found
1. Base runtime activation proposal/approval/disable writes were not transaction-bound to audit evidence.
2. Base runtime proposal/approval did not require exact schema readiness before governance state could advance.
3. Base runtime proposals could overwrite an already-pending proposal, and approval lacked service-level positive actor validation.
4. `GovernanceRestController` mutating export/report routes bypassed the common idempotency guard.
5. The same governance routes lacked common 1 MiB payload validation, invalid-JSON handling and trace-header parity.
6. Uninstall did not clear Future-40 activation/retention options or the Future intelligence cron hook.
7. Uninstall left CF-05 custom roles and administrator capabilities behind despite least-privilege cleanup requirements.
8. Mandatory QA had no final invariant covering base activation atomicity, governance REST parity and Future/uninstall cleanup.

## Corrections
- Base runtime activation now uses row-locked option reads, one-pending-request semantics, schema gates, independent actor validation and audit-bound transactions.
- Disable remains fail-closed even if audit/commit fails.
- Governance export/report mutations now use the shared idempotency/request-size/JSON/trace discipline.
- Uninstall removes runtime/Future configuration, all CF-05 scheduled hooks, custom roles and administrator capabilities while retaining governed analytics data by default.
- Added `release-governance-check.py` to mandatory QA.

Repository/source correction only; this does not claim staging acceptance, live deployment or operational verification.
