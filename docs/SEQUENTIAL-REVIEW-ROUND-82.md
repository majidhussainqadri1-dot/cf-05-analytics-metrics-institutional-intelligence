# Sequential Review Round 82 — Export, provider, deletion and restore authorization audit

Secure export request/download/revocation, provider governance, deletion propagation request and restore-point record/verification boundaries were fully reviewed before correction. The defect ledger was frozen first.

## Frozen defect ledger (9)
1. Export request relied on the REST `smai_export_metrics` permission without service-layer enforcement.
2. Export download relied on transport authorization and ownership/token checks, but lacked service-layer `smai_export_metrics`.
3. Export revocation allowed the requester through object ownership without independently requiring `smai_export_metrics` (or administrative `smai_manage_access`).
4. Provider registration lacked service-layer `smai_manage_providers`.
5. Provider lifecycle transition lacked service-layer `smai_manage_providers`.
6. Deletion request lacked service-layer `smai_manage_deletions`.
7. Restore-point recording lacked service-layer `smai_restore`.
8. Restore verification lacked service-layer `smai_restore`.
9. Permanent authorization QA did not protect these export/provider/deletion/restore boundaries.

## Corrections after review completion
All eight user-triggered service operations now fail closed on their required capability before protected state processing. Existing ownership, token, provider evidence, deletion scope, independent-review and restore-integrity checks remain cumulative. Permanent authorization invariants now cover all corrected boundaries.

## Truth boundary
This proves only the current repository service contract after CI. It does not prove deployed capability assignments, external provider state, deletion completion on live stores, backup integrity or a live restore rehearsal.
