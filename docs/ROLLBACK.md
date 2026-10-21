# Rollback

- Runtime can be placed in `safe_mode` without deleting contracts, data or evidence.
- New ingestion, workers, reports and exports fail closed when activation evidence or environment compatibility is absent.
- Dataset activation is pointer-based: restore the prior active build and invalidate affected snapshots rather than reversing source facts.
- Metric corrections create a new version/difference report; old versions remain visible as deprecated/invalidated evidence.
- Provider cutover retains parity/shadow evidence and does not purge the old provider until verified exit.
- Database rollback favors forward-compatible compensation; destructive down-migrations are prohibited.
- Code rollback requires package checksum, compatibility verification, queue reconciliation and fresh smoke tests.
