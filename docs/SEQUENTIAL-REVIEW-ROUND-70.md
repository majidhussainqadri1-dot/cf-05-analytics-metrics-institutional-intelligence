# Sequential Review Round 70 — Pipeline projection, checkpoint and lineage atomicity

This round followed the required discipline: the pipeline audit was completed in full and the defect ledger was frozen before corrections began. Full repository QA must pass before Round 71 begins.

## Frozen defect ledger (7)
1. A failed dataset-row INSERT (`false`) was not distinguished from a duplicate/no-op and could still allow lineage/checkpoint completion.
2. Correction-row fencing updated prior rows without checking for SQL failure.
3. Dataset-build `row_count` advancement was not checked, allowing count drift after a successful projection.
4. Correction fencing, row insertion, build-count advancement and lineage evidence were separate commits rather than one projection transaction.
5. JobRunner now supplies governed job provenance as `_job_uuid`, while PipelineService still read `job_uuid`; lineage edges therefore lost worker-job provenance.
6. Pipeline checkpoint advancement and event `processed_at` persistence were not atomic.
7. Checkpoint watermark parsing used permissive `strtotime()` semantics instead of an exact UTC database timestamp contract.

## Corrections
- Each dataset projection now runs in a fail-closed transaction covering correction fencing, row insertion, build count and lineage evidence.
- SQL failure is explicitly distinguished from idempotent duplicate insertion.
- Lineage binds to the internal governed `_job_uuid`.
- Checkpoint advancement and processed-state persistence now share one completion transaction.
- Checkpoint watermarks require exact `Y-m-d H:i:s` UTC values.
- Permanent pipeline QA invariants now cover these controls.

## Truth boundary
Repository-source review and automated-QA evidence only. This does not prove deployed worker concurrency, MySQL transaction configuration, staging throughput, or live pipeline parity.
