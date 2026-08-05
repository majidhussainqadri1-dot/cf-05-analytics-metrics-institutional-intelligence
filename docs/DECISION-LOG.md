# Decision Log

## D-001 — Conditional runtime

The repository may contain complete source, but ingestion and production dashboards remain disabled until activation evidence exists.

## D-002 — Canonical ownership

CF-05 stores derivative analytics truth only. Native domain owners remain authoritative.

## D-003 — No raw sensitive domains

Raw clinical, private-message, identity-evidence and payment-secret fields are rejected by design.

## D-004 — Version-pinned metrics

Dashboards and reports must pin metric versions; silent denominator or definition switches are forbidden.

## D-005 — Analytics informs, never commands

Reports can support decisions but cannot directly execute clinical, financial, moderation, publication or ranking actions.
