# CF-05 Review Round 9 — Repository Hygiene and Documentation Parity

Audit completed fully before corrections; the defect ledger was frozen first.

## Defects found
1. Retired `contents: write` one-shot workflows for Future-40, forty-round recovery and coding finalization remained retriggerable.
2. Their stale patch/recovery scripts remained in the source tree and could replay obsolete transformations.
3. Full QA had no repository-hygiene invariant preventing retired mutation tooling or `.codex` markers from returning.
4. Future-40 source-map/changelog/status documentation had not incorporated the later Round 5-8 governance hardening.

## Corrections
- Removed all six retired mutation workflow/script artifacts.
- Added `repository-hygiene-check.py` to mandatory source QA; persistent workflow allowlist is now only `ci.yml`.
- Updated Future-40 source map, changelog and status text to match the hardened current architecture without claiming staging/live completion.

Repository/source evidence only; staging, deployment and operational state remain separate.
