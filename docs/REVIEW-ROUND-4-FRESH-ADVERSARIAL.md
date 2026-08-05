# Review Round 4 — Fresh Adversarial Verification

A fresh review was performed after Round 3 corrections, without treating earlier conclusions as authoritative.

## Negative paths checked in source

- prohibited dataset fields and C4/C5-like raw schemas are rejected;
- dataset and metric versions are explicit and immutable contracts;
- duplicate pipeline requests resolve idempotently;
- invalid and stale job transitions fail with conflict status;
- requester cannot approve own pipeline job;
- snapshots cannot use inactive metric versions or unapproved dimensions;
- tiny cohorts are suppressed before persistence;
- reports and exports require active, unexpired access projects;
- every report/export metric pins an exact active version;
- export columns and row counts are bounded;
- export links have a maximum seven-day lifetime and are never returned before secure generation;
- experiments cannot omit sample, duration, exclusions or analysis plan;
- experiment assignment remains with a named native authority;
- decision owner and approver cannot be the same person;
- deletion fails closed without the private pseudonym key;
- retention holds block deletion;
- expired projects/exports are revoked by scheduled retention;
- runtime stays disabled by default and analytics does not issue domain mutations.

## Decision

The corrected source is accepted as the coding-complete `1.0.0-rc.1` candidate, subject to exact-head CI and the separate non-source acceptance gates listed in the master plan.
