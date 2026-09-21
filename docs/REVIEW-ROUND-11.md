# Review Round 11 — Activation and Authorization Re-Audit

The round was completed before corrections began.

## Confirmed defects

1. An already-active Future-40 feature re-checked base runtime state but did not re-check the global Future-40 activation approval on each non-dry execution. A later global disable could therefore leave a direct active-run path open until each feature was separately disabled.
2. The export revocation route admitted `smai_audit`; that capability belongs to the read-only auditor role and must not authorize mutations.

## Corrections

- Active Future-40 execution now fails closed unless `FutureActivationService::isApproved()` remains true.
- Export revocation now requires export/access management authority; the read-only auditor path was removed.
- A permanent authorization invariants QA check prevents regression.

Staging/live state is not asserted by this source review.
