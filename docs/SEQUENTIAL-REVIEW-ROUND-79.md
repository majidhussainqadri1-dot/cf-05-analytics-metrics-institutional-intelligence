# Sequential Review Round 79 — Narrative, experiment and decision authorization parity

Narrative, experiment-analysis, decision-record and their REST permission boundaries were audited completely before any correction. The defect ledger was then frozen.

## Frozen defect ledger (9)
1. Narrative creation relied on the REST `smai_manage_reports` gate without service-layer enforcement.
2. Narrative publication relied on the REST `smai_approve_catalog` gate without service-layer enforcement.
3. Experiment creation lacked service-layer `smai_manage_experiments` enforcement.
4. Experiment state transitions lacked service-layer `smai_manage_experiments` enforcement.
5. Experiment analysis creation lacked service-layer `smai_manage_experiments` enforcement.
6. Analysis publication lacked service-layer `smai_approve_catalog` enforcement.
7. Decision recording lacked service-layer `smai_manage_experiments` enforcement.
8. Decision outcome recording lacked service-layer `smai_manage_experiments` enforcement.
9. Existing experiment/reporting invariant suites did not permanently protect these narrative/experiment service-layer authorization contracts.

## Corrections after review completion
- Added explicit service-layer capability checks before validation/state access for all eight mutation classes.
- Preserved independence, enhanced review, object state, evidence freshness and ownership rules as additional authorization constraints.
- Extended permanent QA invariants for narrative, experiment, analysis and decision capability enforcement.

## Truth boundary
These are repository-layer authorization guarantees. Actual deployed WordPress roles, capabilities and live integrations remain unverified.
