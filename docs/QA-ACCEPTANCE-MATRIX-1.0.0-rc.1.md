# CF-05 QA Acceptance Matrix — 1.0.0-rc.1

| Domain | Positive path | Negative/adversarial path |
|---|---|---|
| Event ingestion | approved signed event accepted | unknown schema, bad signature, replay, forbidden value quarantined/rejected |
| Dataset/model | valid C1-C3 derivative contract created | prohibited raw fields, duplicate contract, invalid version rejected |
| Lineage | source-to-target edge stored | missing owner/version/transform rejected |
| Pipeline | planned→dry-run→shadow→compare→approve→activate | stale/invalid transition and self-approval rejected |
| Metric snapshot | active exact version and approved dimensions stored | inactive version, tiny cohort, bad window, unapproved dimension rejected |
| Report | active project and pinned versions scheduled | expired project, invalid recipient/version rejected |
| Export | bounded aggregate export requested | raw columns, excessive scope, expired project/link rejected |
| Experiment | predeclared design stored | missing sample/duration/exclusions/analysis plan rejected |
| Decision | evidence and independent approver recorded | self-approval and automatic deployment unavailable |
| Deletion | pseudonymous rows removed and exports revoked | missing key, invalid key or active hold fails closed |
| Retention | expired data/projects/exports closed | active retention holds preserved |
| Runtime | approved evidence can activate controlled states | missing evidence/secrets/dependencies keeps runtime closed |

This matrix is a source-level acceptance specification; executable integration and staging evidence remain required.
