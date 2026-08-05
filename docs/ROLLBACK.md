# Rollback

- Set `smai_runtime_state` to `safe_mode` or `catalog_only`.
- Revoke ingestion service allowlist and rotate external secrets where compromise is suspected.
- Stop consumers from reading affected metric versions.
- Mark defective metrics invalidated and freeze dependent reports.
- Restore approved code/schema/snapshot checkpoint in an isolated environment.
- Reapply deletion/anonymization and access-revocation ledgers before reopening queries.
- Reconcile source owners, event checkpoints, quarantine and report hashes.
- Record the rollback decision, owner, evidence and exit criteria.

Automatic table deletion is intentionally absent.
