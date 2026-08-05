# Local QA — 1.0.0-rc.2

Run:

```bash
bash scripts/qa.sh
bash scripts/verify-deterministic-build.sh
```

## Current result

- PHP syntax: PASS.
- Executable tests: 14 PASS, 0 FAIL.
- JSON contracts/manifests: PASS.
- Architecture/traceability: 21 required implementation files, 37 governed tables and all 35 requirement IDs: PASS.
- Secret and prohibited-pattern scan: PASS.
- Release identity: PASS.
- ZIP integrity and deterministic byte-for-byte rebuild: PASS.
- Deterministic package SHA-256: `2269d06a13a43858c040456c76d2e6eef4bdd2b03b023cd83c64f712b86f4c5d`.

The executable suite covers validators, privacy/sensitive-value rejection, lifecycle separation, deterministic canonical JSON, CSV formula neutralization, contextual authenticated encryption, transformations/filters, metric semantic closure, statistical uncertainty/practical significance and duplicate experiment variants.
