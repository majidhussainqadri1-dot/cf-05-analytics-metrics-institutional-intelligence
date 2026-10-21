# Sequential Review Round 89 — Report delivery lifecycle and reactivation safety

The report creation/activation/run/access/control lifecycle, export delivery controls, bearer-token transport, recipient authorization, access-project expiry and delivery revocation behavior were reviewed completely before correction. The defect ledger was frozen first.

## Frozen defect ledger (3)
1. Pausing an active report did not revoke outstanding report-delivery tokens. Those links became usable again after resume if still within their TTL.
2. Updating a paused report moved it back to draft/reapproval but retained deliveries generated under the former definition; those stale deliveries could become usable again after later activation.
3. Report resume checked project state and approver independence but did not revalidate the stored recipient, metric, privacy and access contract before reactivating scheduling.

## Corrections after review completion
- Pause now revokes all outstanding deliveries atomically with the state transition.
- Any report update revokes prior deliveries inside the same transaction before the changed definition can return to the approval lifecycle.
- Resume now revalidates recipients and metric/access/privacy contracts against current state.
- Extended permanent reporting-governance invariants for these reactivation boundaries.

## Truth boundary
This is repository delivery-lifecycle evidence only. It does not establish live token inventories, actual File-19 delivery behavior, reverse-proxy controls or deployed report state.
