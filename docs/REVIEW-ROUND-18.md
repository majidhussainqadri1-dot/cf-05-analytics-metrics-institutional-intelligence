# Review Round 18 — Future-40 Runtime, Artifacts and Authorization

The complete Future-40 registry/service/activation/artifact/REST execution surface was audited before corrections began.

## Confirmed defects
1. `FutureFeatureService` relied on REST permission callbacks for configuration, lifecycle and feature execution; direct domain-service invocation did not enforce the governing capability.
2. FUT-038 (Analytics Transparency Center) used the read-only `smai_view_transparency` capability even though an active run persists a new transparency governance record.
3. Analytics incident creation silently ignored unsupported top-level payload fields instead of enforcing a closed incident contract.
4. `scheduledTick()` checked the global Future-40/base runtime gate before selecting candidates but did not recheck those gates after locking an individual feature, leaving a disable-vs-scheduled-run race window.

## Corrections
Domain-layer authorization now mirrors the governing capability model; FUT-038 execution requires management authority; incident payloads are closed-schema; scheduled evidence generation revalidates Future-40 approval, base runtime and schema readiness after the feature lock; permanent QA invariants were added.

No staging/live state is asserted by this source review.
