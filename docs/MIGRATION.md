# Migration Strategy

1. Inventory current event emitters, schemas, analytics tables and reports.
2. Freeze canonical owner contracts and prohibited-field exclusions.
3. Register versioned event and metric contracts without enabling ingestion.
4. Dry-run historical transformation into shadow tables.
5. Compare counts, dedupe, late-event behavior, deletion application and sample metrics.
6. Obtain privacy/security/domain approval.
7. Enable staging ingestion, then shadow reports.
8. Cut over consumers only after exact-version parity and rollback rehearsal.
9. Preserve invalidated historical metric versions as evidence; never silently rewrite them.
