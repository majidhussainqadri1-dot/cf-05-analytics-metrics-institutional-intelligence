# Sequential Review Round 78 — Reporting service authorization parity

The report creation, activation, control, unsubscribe and REST permission paths were audited end to end before correction. The defect ledger was frozen first.

## Frozen defect ledger (7)
1. `ReportService::create()` relied on the REST `smai_manage_reports` permission and lacked domain/service-layer capability enforcement.
2. `ReportService::activate()` relied on the REST `smai_approve_catalog` permission and lacked service-layer enforcement.
3. `ReportControlService::update()` checked ownership but not the `smai_manage_reports` capability required by its REST route.
4. Report pause allowed an owner through domain logic without independently enforcing `smai_manage_reports`.
5. Report resume enforced independence but not the `smai_approve_catalog` capability required by the route.
6. Report revoke could be invoked internally by an owner without the transport-layer `smai_manage_reports` or `smai_manage_access` capability gate.
7. The reporting-governance invariant suite did not permanently protect these service-layer authorization requirements.

## Corrections after review completion
- Added fail-fast service-layer capability enforcement for report create, activate, update, pause, resume and revoke.
- Preserved object ownership, access-project, separation-of-duties and recipient unsubscribe checks as additional—not substitute—authorization layers.
- Extended permanent reporting-governance QA to detect loss of these capability gates.

## Truth boundary
This is repository authorization-hardening evidence. It does not establish the correctness of roles/capabilities configured on any deployed WordPress instance.
