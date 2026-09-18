# Sequential Review Round 76 — CI and deterministic release-identity audit

The CI workflow, deterministic builder, package-parity verifier, release-governance checks and release-facing README were reviewed completely before any correction began. The following ledger was then frozen.

## Frozen defect ledger (6)
1. CI still used `actions/checkout@v4`, and current GitHub Actions logs emitted the Node.js 20 deprecation warning.
2. `scripts/build-package.py` hard-coded candidate version/schema/contract values instead of deriving the current release identity from canonical source constants.
3. `scripts/package-parity.py` hard-coded the candidate version, allowing future source-version changes to drift from parity verification.
4. `scripts/verify-deterministic-build.sh` hard-coded rc.10 artifact paths and a release-specific temporary filename.
5. The release-governance QA gate did not permanently reject reintroduction of hard-coded release identities in the builder/parity scripts.
6. `README.md` named a fixed latest review round, causing immediate documentation drift on the next governed round.

## Corrections after review completion
- Advanced checkout to v5 to remove the deprecated Node-runtime path.
- Release ZIP, SBOM and package-manifest identity now derive from `SMAI_VERSION`, `SMAI_SCHEMA_VERSION` and `SMAI_CONTRACT_VERSION`.
- Package parity and deterministic verification derive the current candidate dynamically.
- Added permanent release-governance checks against hard-coded candidate identity.
- Made README review-evidence wording round-neutral.
- Renamed the package-manifest timestamp field to make clear that it is the fixed reproducible archive timestamp, not a release-date claim.

## Post-correction QA
The first correction commit intentionally removed hard-coded release identity. Exact-head CI then exposed three stale assertions in `schema-contract-check.py` that still required those literals. Those assertions were updated to verify dynamic identity derivation instead; this was a correction-regression repair, not a new review round.

## Truth boundary
Deterministic packaging and CI correctness are repository-source evidence only. They do not prove that any ZIP is deployed, that deployed files match this HEAD, or that staging/live DB migration and operational state are verified.
