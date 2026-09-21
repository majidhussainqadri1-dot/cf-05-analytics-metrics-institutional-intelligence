# Review Round 19 — Infrastructure, Runtime Activation and Worker Reliability

The complete runtime-gate, activation, rate-limit, job-lease, worker, activation/upgrade and uninstall infrastructure surface was audited before corrections began.

## Confirmed defects
1. Rate limiting stopped incrementing at exactly the configured limit and then returned `count <= limit`, so every later request in the same bucket remained permitted instead of being rejected.
2. `RuntimeActivationService::propose`, `approve` and `disable` trusted the REST layer for authorization; direct service invocation did not enforce `smai_activate_runtime`.
3. `JobQueue::claim()` did not validate the worker identity, did not fail closed when `START TRANSACTION` failed, and returned a claimed lease without verifying that `COMMIT` succeeded.
4. `JobRunner` ignored a failed success-acknowledgement (`JobQueue::complete()`), allowing a completed side effect to remain leased/runnable and later be retried after lease expiry without an immediate controlled retry state.

## Corrections
The limiter now advances the first over-limit request into a denied state; runtime activation mutations enforce capability at the service boundary; job claims require a bounded worker identity and verified transaction/commit; worker completion persistence failure is converted to a controlled retry/dead-letter path where the lease is still owned; permanent QA invariants were added.

No staging/live state is asserted by this source review.
