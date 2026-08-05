# Data Dictionary

| Table | Purpose | Privacy | Retention |
|---|---|---|---|
| `smai_event_schemas` | Immutable event-contract versions | C2 | Permanent contract history |
| `smai_events` | Minimized derivative analytics events | C2/C3 | Short purpose-bound |
| `smai_quarantine` | Redacted validation/privacy failures | C3 | Very short operational |
| `smai_metrics` | Semantic metric definitions and lifecycle | C2 | Permanent semantic history |
| `smai_metric_snapshots` | Approved aggregate results | C1-C3 | Policy/business schedule |
| `smai_access_projects` | Purpose/duration/dataset access approvals | C3 | Project + audit schedule |
| `smai_audit_log` | Minimized hash-chained evidence | C3 | Security/governance schedule |
| `smai_audit_state` | Serialized audit-chain head; no business data | C2 | Permanent integrity state |
| `smai_governance_transitions` | Event/metric lifecycle transition evidence | C2/C3 | Permanent governance history |
| `smai_deletion_jobs` | Deletion/anonymization reconciliation | C3 | Completion + evidence schedule |
| `smai_quality_issues` | Data-quality/drift incidents | C2/C3 | Operational/audit schedule |
| `smai_exports` | Export job metadata; never raw file content | C3 | TTL + audit schedule |
| `smai_ingestion_nonces` | Atomic service-request replay prevention | C2 | Ten-minute security window |
