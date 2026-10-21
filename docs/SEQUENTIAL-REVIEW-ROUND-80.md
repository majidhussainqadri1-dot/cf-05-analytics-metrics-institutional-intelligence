# Sequential Review Round 80 — Access-project and dashboard authorization audit

Access-project request/approval/revocation, dashboard registration/activation/view and their REST/audience contracts were reviewed to completion before correction. The defect ledger was frozen first.

## Frozen defect ledger (7)
1. Access-project request relied on REST `smai_manage_access` without service-layer enforcement.
2. Access-project approval relied on REST `smai_manage_access` without service-layer enforcement.
3. Access-project revocation relied on REST `smai_manage_access` without service-layer enforcement.
4. Dashboard registration relied on REST `smai_manage_reports` without service-layer enforcement.
5. Dashboard activation relied on REST `smai_approve_catalog` without service-layer enforcement.
6. Dashboard audience allowed `smai_audit`, but the REST read route allowed only `smai_view_insights`; transport and stored audience contracts disagreed.
7. Permanent authorization QA did not protect the access-project/dashboard service and route capability parity.

## Corrections after review completion
- Added service-layer capability enforcement to all access-project mutations and dashboard register/activate.
- Dashboard reads now require either `smai_view_insights` or `smai_audit` at both transport/service boundaries, followed by the stored dashboard audience check.
- Added permanent authorization invariants for all corrected boundaries.

## Truth boundary
Repository authorization parity does not establish deployed user-role configuration, live data access grants or operational access-review evidence.
