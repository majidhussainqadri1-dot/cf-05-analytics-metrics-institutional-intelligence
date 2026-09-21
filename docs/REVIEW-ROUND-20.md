# Review Round 20 — Event ingestion, quarantine and privacy gateway

The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.

## Confirmed defects
1. Accepted-event ingestion starts a transaction without checking whether the transaction actually started.
2. The identical-event duplicate path returns success without verifying that its transaction commit succeeded.
3. Quarantine persistence and its audit evidence are separate commits, so rejected-event evidence can become non-atomic.
4. Schema timestamp properties accept loose/relative strtotime syntax instead of the strict RFC3339 policy used by the event envelope.
5. Malformed non-scalar actor/object/deletion envelope references can be silently collapsed to null by pseudonymization instead of being rejected.

## Corrections
- Made accepted-event and quarantine transactions fail closed on transaction/commit/audit failure.
- Rejected malformed non-scalar envelope references before pseudonymization.
- Aligned event-property timestamps with strict RFC3339 parsing.

## Lifecycle boundary
This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.
