# Sequential Review Round 40 — Event envelope, privacy gateway and contract-exact input semantics

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (5)
1. Event envelope reference validation accepts booleans/floats although the public contract permits only string, integer or null references.
2. PrivacyGateway pseudonymizes unsupported scalar reference types instead of enforcing the envelope reference contract.
3. Event timestamp validation relies on regex + strtotime and can normalize impossible calendar dates instead of rejecting them exactly.
4. PrivacyGateway timestamp fields use the same non-exact calendar validation.
5. Event schema numeric bounds can accept non-finite programmatic float values.

## Corrections
- Aligned runtime event references with the published envelope types.
- Made event/property timestamps calendar-exact and rejected non-finite numeric schema bounds.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
