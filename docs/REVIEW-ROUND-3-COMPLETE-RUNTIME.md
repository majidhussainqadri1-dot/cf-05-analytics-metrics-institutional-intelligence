# Review Round 3 — Complete Runtime Correction

## Scope

Fresh adversarial review of the transition from the 0.1.0 foundation to the 1.0.0-rc.1 complete coding candidate.

## Defects found and corrected

1. **Foundation-only schema** — corrected through additive governed tables for datasets, lineage, jobs, quality rules, reports, experiments, decisions, holds and providers.
2. **Missing exact-version snapshot publication** — corrected with active metric lookup, dimension allowlist, minimum-cohort suppression, freshness and caveat persistence.
3. **No governed backfill/rebuild lifecycle** — corrected with idempotency keys, closed transition graph, independent approval and concurrent-state guard.
4. **No scheduled-report contract** — corrected with project ownership, exact metric versions, recipients, cadence, TTL and expiry.
5. **No secure export request contract** — corrected with project scope, aggregate-only columns, exact metric versions, row limits, seven-day maximum expiry and formula-neutralization flag.
6. **No experiment/decision records** — corrected with predeclared design, external assignment authority, guardrails, separation of duties and no-automation declaration.
7. **Incomplete privacy lifecycle** — corrected with retention holds, deletion-key propagation, export revocation, recompute signal and project/export expiry.
8. **Activation schema ordering risk** — corrected by registering the complete schema installer as a second idempotent activation hook and as a boot-time upgrade.
9. **Version/manifest drift** — corrected to source `1.0.0-rc.1`, schema `1.0.0`, contract `1.1.0`.

## Remaining evidence gates

No known coding-level Critical/High defect is intentionally left open in this review. Staging, real-provider integration, deterministic package, browser/accessibility/load/security and operational acceptance remain evidence gates rather than hidden coding claims.
