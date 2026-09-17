# Review Round 16 — Experiments, Analyses and Decision Records

The complete experiment/analysis/decision surface was audited before corrections began.

## Confirmed defects
1. Experiment create/transition/assignment/analysis/publish paths opened caller transactions but invoked `AuditLogger::log()`, creating nested transaction semantics instead of atomic audit append.
2. Decision-record creation and outcome updates persisted state before best-effort audit logging; audit failure could leave an unaudited governance mutation.
3. The guardrail-breach WordPress action was emitted before analysis persistence completed, so external listeners could observe a breach event even if the analysis transaction later failed.

## Corrections
All experiment transaction-bound audit writes now use `logInOpenTransaction`; decision record/outcome changes commit atomically with audit evidence; guardrail breach signaling occurs only after durable analysis commit; a permanent QA invariant gate was added.

No staging/live state is asserted by this source review.
