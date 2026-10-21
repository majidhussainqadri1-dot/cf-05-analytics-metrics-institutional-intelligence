# Review Round 13 — Metric Query, Cohort and Privacy Accounting

The review completed before corrections began, covering metric registration, exact-version query authorization, cohort suppression, differencing resistance, freshness and privacy accounting.

## Confirmed defects
1. Query privacy dimension hashes/fingerprints and actor references used plain SHA-256, allowing low-cardinality dictionary analysis of stored privacy evidence.
2. Metric query audit fingerprint fell back to unkeyed SHA-256 when the pseudonym key was unavailable.
3. Privacy budget/slice accounting was vulnerable to concurrent read-check-insert races.
4. A failed privacy-accounting insert was ignored, permitting a result to be disclosed without durable budget evidence.
5. Metric registration and its audit record were not transactionally atomic; cleanup-after-audit-failure could itself fail.

## Corrections
All privacy fingerprints/references are keyed HMACs, per-actor/project/metric accounting is serialized with a bounded database advisory lock, privacy evidence persistence is fail-closed, metric query audit has no unkeyed fallback, and metric registration/audit commit atomically. Permanent QA invariants were added.

No staging/live claim is made by this source review.
