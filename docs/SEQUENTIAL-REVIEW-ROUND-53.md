# Sequential Review Round 53 — Snapshot strictness, governance revalidation and revision concurrency

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 54 begins.

## Frozen defect ledger (5)
1. Snapshot enqueue accepted loose `strtotime()` date strings instead of the repository's strict RFC3339 contract.
2. Snapshot enqueue did not reject inactive metrics, unapproved dimensions or privacy-invalid slices before creating background jobs.
3. Snapshot revision selection occurred outside a serializing transaction, allowing concurrent jobs to race on the same revision identity.
4. Metric, dataset and active-build governance state was not revalidated under lock immediately before snapshot publication.
5. A new revision could change an already invalidated prior snapshot to `superseded`, weakening preserved invalidation evidence.

## Corrections
- Added strict RFC3339/calendar validation at enqueue and worker execution.
- Added pre-enqueue metric/dimension/privacy validation.
- Serialized publication with locked metric/dataset/build and snapshot-revision reads inside one transaction.
- Revalidated active metric, published dataset and active build in the publication transaction.
- Supersede only a prior snapshot whose current state is actually `published`; invalidated evidence remains invalidated.
- Updated the legacy SR-20..29 static invariant matcher to be whitespace-insensitive after the stronger snapshot code was reformatted; this was a QA-harness compatibility correction, not an additional product defect.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
