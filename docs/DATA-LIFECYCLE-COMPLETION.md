# Data Lifecycle Completion

The source candidate implements lifecycle contracts for:

- immutable event and metric versions;
- datasets and lineage;
- pipeline/backfill/rebuild/provider-exit/restore jobs;
- access projects and automatic expiry;
- scheduled reports and report runs;
- secure export requests, revocation and expiry;
- experiments and human decision records;
- retention holds and deletion propagation;
- quality issues and audit evidence.

No destructive uninstall is performed automatically. Raw/minimized events and quarantine records use bounded retention. Aggregate retention remains subject to re-identification risk and approved schedules. Deletion propagation requires the private pseudonym key and fails closed when a lawful/safety hold exists.
