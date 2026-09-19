# Privacy Architecture

- allowlist-first event fields; unknown fields are dropped;
- stable pseudonyms are HMAC-derived with a private rotatable key and context separation;
- consent, purpose, age/minor policy and region/provider restrictions are evaluated before storage and use;
- C4/C5 clinical, identity, message, credential and payment content is excluded rather than merely hidden;
- raw events have the shortest retention; pseudonymous modeled data is bounded; aggregates persist only when re-identification risk is controlled;
- every query/export/report requires an active purpose-limited access project and exact approved fields/dimensions;
- minimum cohort suppression and allowlisted drilldown prevent small-cell and differencing disclosure;
- deletion keys propagate through events, derivative rows, experiment facts, snapshots, exports, deliveries and providers with reconciliation evidence;
- the words anonymous or differentially private are used only when technically implemented and evidenced; the current default is pseudonymous/aggregate.
