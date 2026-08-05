# Review Round 6 — Fresh Adversarial Verification

Date: 2026-08-06

## Negative-path focus

The corrected `1.0.0-rc.3` source was re-read for privacy leakage, authorization broadening, stale-state writes, contract drift, accessibility regressions, unsupported PHP syntax and truthful completion claims.

## Fresh defects found and corrected

1. Initial privacy/report control return types used standalone `true` unions, which are not compatible with PHP 8.1; changed to `bool|WP_Error`.
2. Metric definitions needed normalized privacy controls to be persisted before validation and hashing; registration order corrected.
3. Repeated dashboard instances required generated IDs rather than a static heading ID; `wp_unique_id()` adopted.
4. Report state changes required row-version compare-and-swap and immediate delivery-token revocation; enforced.
5. Export revocation required encrypted payload purge in addition to state/token invalidation; enforced.
6. Runtime/public quality state vocabulary was normalized to the published contract.

## Verification law

The final source is not accepted merely because documentation says complete. Exact-head PHP 8.1/8.2/8.3 CI, deterministic package verification and artifact integrity must pass. Hostinger staging, browser/accessibility/load/security, backup/restore, rollback and Founder acceptance remain mandatory external gates.
