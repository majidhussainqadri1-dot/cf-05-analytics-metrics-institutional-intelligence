# Data Dictionary

| Domain | Principal tables | Classification | Lifecycle |
|---|---|---|---|
| Event contracts and minimized events | `event_schemas`, `events`, `quarantine`, `ingestion_nonces` | C2/C3 derivative | contracts retained; events short and purpose-bound; quarantine very short |
| Derivative warehouse | `datasets`, `dataset_builds`, `dataset_rows`, `checkpoints`, `lineage_edges` | C1–C3 | versioned builds; effective-dated rows; active build pointer; bounded retention |
| Quality | `quality_rules`, `quality_results`, `quality_issues` | Internal/restricted evidence | retained to explain publication and correction decisions |
| Semantic metrics | `metrics`, `metric_snapshots` | aggregate C1–C3 | metric history permanent; snapshots according to approved schedule |
| Access and exports | `access_projects`, `exports`, `export_payloads` | C3 | automatic expiry/revocation; encrypted payload purge |
| Reports and dashboards | `dashboard_definitions`, `dashboard_widgets`, `reports`, `report_deliveries`, `narratives` | aggregate/restricted | versioned; links expire; revoked access propagates |
| Experiments and decisions | `experiments`, `experiment_facts`, `experiment_analyses`, `decision_records` | C2/C3 aggregate evidence | assignment authority external; analysis/decision history retained |
| Deletion, provider and restore | `deletion_jobs`, `deletion_reconciliations`, `providers`, `restore_points` | restricted governance | retained as accountable evidence without raw deleted content |
| Operations | `jobs`, `rate_limits`, `idempotency_keys`, `audit_log`, `audit_state`, `governance_transitions` | restricted operational | bounded operational retention; audit governed separately |

Pseudonymous references are not public identity. The analytics surrogate identifiers never replace native owner identifiers.
