# Post-cycle REST Security Hardening — 2026-09-19

After the requested SR-74..SR-83 ten-round cycle had already closed green, an additional security spot-check identified three repository-level defects. This document is intentionally **not** numbered as another sequential review round, so the historical SR-74..SR-83 evidence remains intact.

## Confirmed defects
1. Export download bearer tokens were accepted through a URL query parameter, increasing exposure to browser history, proxy/access logs and referrer propagation.
2. Report-delivery bearer tokens used the same query-string transport.
3. The global raw-download REST serving filter trusted an internal response marker without first binding itself to the exact CF-05 export-download route.
4. Exact-head CI was green but still emitted a GitHub Actions Node.js 20 deprecation warning from older `checkout`/`upload-artifact` action majors.

## Corrections
- Export download now requires `X-Sabri-Download-Token`.
- Report delivery now requires `X-Sabri-Report-Token`.
- Raw CSV output is constrained to the exact CF-05 export download route, fixed to CSV content type and explicitly no-cache.
- Permanent security-static QA now rejects query-string bearer-token regression and loss of these route/header boundaries.
- Updated `actions/checkout` and `actions/upload-artifact` to current v7 majors after verifying current upstream releases, removing the deprecated Node.js 20 action-runtime path.

## Evidence discipline
This is repository-source hardening performed after the ten requested numbered rounds. It does not alter the historical defect ledgers for SR-74..SR-83 and does not constitute staging/live/deployed-code verification.
