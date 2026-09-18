# Implementation Status — 1.0.0-rc.8

| Status | Result |
|---|---|
| Specified | Complete for approved `CF05-FR-001..CF05-FR-035`, applicable three-plan controls, and approved `CF05-FUT-001..CF05-FUT-040` Future-40 expansion |
| Coded | Future-40 source expansion committed; forty prior fresh review/fix rounds remain preserved; zero known source defects after governed source QA |
| Packaged | Deterministic ZIP, checksum, package manifest and CycloneDX SBOM are required and verified by the exact-head CI release-build gate |
| Automated QA | Must be proven by GitHub Actions on the current exact repository HEAD; historical green runs do not substitute for current-head evidence |
| Staging accepted | Pending |
| Live deployed | No repository evidence; live reality must be verified independently |
| Operational | No repository evidence; operational reality must be verified independently |

Candidate `1.0.0-rc.8`; schema `1.4.1`; contract `1.4.0`; runtime default `foundation_disabled`.

The initial governed Future-40 expansion was introduced by `d3e815d806ebf6cd86955a02ba05362605851d29`; subsequent governed review/fix rounds hardened API mutation safety, activation atomicity, actor boundaries, persistent artifact binding, derivative retention/research expiry, and scheduled-evidence idempotency/provenance. The exact current repository HEAD, not the initial expansion commit, is the source-truth identity for any present-tense verification. The branch continues to contain executable handlers and QA coverage for `CF05-FUT-001..CF05-FUT-040`, 45 governed tables, activation-evidence gates, independent approval controls, aggregate/advisory-only invariants and deterministic packaging.

Future-40 runtime features remain disabled until their separate configuration, independent approval, exact Future-40 activation-evidence hash and base CF-05 environment/runtime gates pass. Source coding does not authorize treating GitHub source, a package, CI, staging, live deployment or operational status as interchangeable realities.
