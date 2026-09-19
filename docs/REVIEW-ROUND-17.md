# Review Round 17 — Deletion, Retention, Provider Exit and Restore

The complete privacy-deletion, retention, provider-exit and restore/reproducibility surface was audited before corrections began.

## Confirmed defects
1. Deletion request/completion audit evidence was not reliably part of the caller transaction; completion could persist before audit failure and then become difficult to retry correctly.
2. Provider registration had no audit record, provider transitions used best-effort audit after mutation, and activation overwrote the identity of the independent approver.
3. Restore-point recording and verification mutated state with best-effort audit; the verification-completed action could fire even when durable audit/state evidence failed.
4. Restore deletion verification checked `experiment_facts.subject_ref` while deletion itself is keyed on `experiment_facts.deletion_key`, so restored deleted experiment facts could escape the verification test.
5. Retention ignored database-operation failures and had no durable run-level audit evidence, allowing silent partial retention.

## Corrections
Deletion completion/request evidence is atomic; provider governance is audit-atomic and preserves approver provenance; restore record/verify is fail-closed and checks the correct deletion key; post-restore signals occur after commit; retention runs transactionally, fail on any database error and append system audit evidence; permanent QA invariants were added.

No staging/live state is asserted by this source review.
