# Sequential Review Round 85 — Runtime activation prerequisite completeness

The base runtime gate, activation proposal/approval transaction, health reporting and all three private configuration dependencies were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (3)
1. Staging/production activation could be proposed and approved while required private runtime secrets were missing; only schema and evidence hashes were mandatory.
2. If required private configuration was removed after approval, `ingestionEnabled()` and `queryEnabled()` did not fail closed at the central runtime gate.
3. Health reporting detected missing ingestion/pseudonym configuration only in a narrower condition and did not treat a missing export-encryption key as an active-runtime degradation.

## Corrections after review completion
- Added `RuntimeGate::privateConfigurationReady()` covering ingestion, pseudonymization and export-encryption configuration.
- Staging/production proposal and approval now reject incomplete private configuration.
- Ingestion/query/worker gates now fail closed if required private configuration disappears after approval.
- Health now exposes and evaluates the same centralized readiness result.
- Extended permanent runtime invariants for this activation prerequisite.

## Truth boundary
These are source-level activation gates. They do not prove that any deployed environment actually has the required constants configured.
