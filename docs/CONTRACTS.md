# Contracts

## Event ingestion

`POST /wp-json/sabri-analytics/v1/events`

Headers:

- `X-Sabri-Service`
- `X-Sabri-Timestamp`
- `X-Sabri-Signature`

Signature material:

`METHOD + "\n" + ROUTE + "\n" + TIMESTAMP + "\n" + SHA256(BODY)`

The event must match an **active** immutable event schema. Unknown versions are quarantined.

## Metric query

`GET /wp-json/sabri-analytics/v1/metrics/{metric_id}`

Required parameters: `version`, `window_start`, `window_end`, `purpose`. Optional `dimensions` is a JSON object. Only approved dimensions and exact version-pinned snapshots are eligible. Small cohorts are suppressed.

## Integration ownership

- File 00: identity, role, membership and consent assertions.
- File 20: global shell and route placement.
- File 24: assurance evidence and governance.
- File 25: visual components and responsive/RTL presentation.
- File 23: authorized publishing-dashboard summaries.
- File 26: ranking/recommendation assignment and policy; CF-05 analyzes facts only.
