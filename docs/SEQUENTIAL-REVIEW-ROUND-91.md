# Sequential Review Round 91 — Future-40 evidence fidelity and configuration integrity

Future-40 configuration, lifecycle approval, active/dry-run execution, scheduled evidence, persistent artifacts, privacy-budget state and global activation binding were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (4)
1. Feature configuration was silently minimized/truncated before persistence and hashing, so the approved config hash did not necessarily bind the exact submitted configuration.
2. Future run request hashes and stored run results were computed from lossy minimized structures; materially different bounded inputs could therefore lose exact provenance in run evidence.
3. Scenario artifacts persisted truncated/cleaned input and output rather than exact governed execution evidence, weakening reproducibility.
4. Feature activation, execution and scheduled evidence trusted the stored `config_hash` without recomputing it from `config_json`; persisted configuration/hash divergence was not fail-closed.

## Corrections after review completion
- Added bounded, non-lossy evidence-shape validation before configuration/run/incident persistence.
- Configuration hashes now bind the exact accepted canonical configuration.
- Run request/result evidence uses exact accepted canonical structures rather than silent truncation.
- Scenario artifacts persist the exact accepted execution input/result.
- Lifecycle transition, active/dry-run execution and scheduled evidence now verify `config_json` against `config_hash`.
- Extended Future-40 QA and authorization invariants for evidence fidelity and config integrity.

## Truth boundary
This is repository-level Future-40 evidence integrity. It does not prove deployed configurations, live scheduled executions, operational AI behavior or production artifact state.
