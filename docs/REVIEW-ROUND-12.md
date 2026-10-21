# Review Round 12 — Event Ingestion and Privacy Gateway

The review was completed against CF05-FR-001..006 and the amended CF-05 privacy/unknown-field acceptance rules before any corrections began.

## Confirmed defects
1. Unknown event property fields were silently dropped instead of failing closed into quarantine.
2. Schema fields marked `required` were not rejected when absent.
3. `minor_policy=aggregate_only` could retain actor/object and field-level pseudonymous references, weakening the promised aggregate-only boundary.
4. Successful event ingestion committed the event/job before audit logging, allowing an `accepted_audit_degraded` state instead of atomic acceptance evidence.
5. Event-schema registration persisted the contract independently from its audit record and ignored audit failure.

## Corrections
Unknown/missing fields now fail closed; minor aggregate-only processing removes individual/pseudonymous refs; accepted event + pipeline job + audit are transactional; schema registration + audit are transactional; permanent QA invariants were added.

No staging or live state is asserted by this source review.
