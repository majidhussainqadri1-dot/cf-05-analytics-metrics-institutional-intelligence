# Sequential Review Round 77 — REST secret transport and raw-response boundary

The complete REST registration surface, permission callbacks, service authentication, runtime/Future activation gates, export/report delivery paths and raw-response filter were audited first. No correction was started until the ledger below was frozen.

## Frozen defect ledger (3)
1. Export download bearer tokens were accepted through the URL query parameter `token`, exposing a secret to browser history, reverse-proxy/access logs and referrer propagation.
2. Report-delivery bearer tokens used the same query-string transport weakness.
3. The global `rest_pre_serve_request` raw-download filter trusted only an internal marker and did not first bind itself to the CF-05 export-download route, creating an unnecessary cross-route response-collision surface.

## Corrections after review completion
- Export tokens now travel only in `X-Sabri-Download-Token`.
- Report-delivery tokens now travel only in `X-Sabri-Report-Token`.
- Raw CSV serving is route-bound to the exact CF-05 export-download namespace and uses fixed CSV content type plus explicit no-cache headers.
- Added permanent static security invariants preventing query-string bearer tokens and loss of the route/header boundary.

## Truth boundary
This closes the confirmed repository-level REST transport defects for this round. It does not assert reverse-proxy configuration, TLS termination, deployed headers, staging behavior or live endpoint parity.
