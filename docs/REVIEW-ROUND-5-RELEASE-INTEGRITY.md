# Review Round 5 — Release Integrity

## Checks

- plugin header, runtime, schema, contract and manifest versions aligned;
- complete schema installer is idempotent and runs both on activation and upgrade;
- source remains public-safe and contains no credentials;
- all newly exposed REST routes require authenticated capabilities;
- exact metric versions are mandatory for snapshots, reports and exports;
- native domain authority remains outside CF-05;
- source-complete, packaged, staging, live and operational statuses remain distinct;
- uninstall remains non-destructive by default.

## Outcome

No known release-integrity Critical/High defect remains in the reviewed source. Exact-head CI and package generation remain separate evidence gates.
