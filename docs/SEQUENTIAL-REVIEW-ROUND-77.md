# Sequential Review Round 77 — Future-40 activation authorization audit

The Future-40 activation service, REST permissions and permanent authorization invariants were reviewed to completion before correction. The frozen ledger follows.

## Frozen defect ledger (4)
1. `FutureActivationService::propose()` trusted its REST caller and did not independently require `smai_manage_future_intelligence`.
2. `FutureActivationService::approve()` did not independently require `smai_approve_future_intelligence`.
3. `FutureActivationService::disable()` did not independently require `smai_approve_future_intelligence`.
4. The permanent Future-40 authorization QA gate checked feature-service authorization but did not protect the activation service from future caller-boundary regressions.

## Corrections after review completion
- Added domain/service-layer capability enforcement to proposal, approval and disable paths.
- Unauthorized activation operations now fail with explicit 403 errors before any transaction or state access.
- Extended the Future-40 authorization invariant checker so REST permission callbacks are not treated as the sole security boundary.

## Truth boundary
This closes the repository service-layer authorization gap. It does not verify deployed code, production roles/capabilities, live configuration or runtime activation state.
