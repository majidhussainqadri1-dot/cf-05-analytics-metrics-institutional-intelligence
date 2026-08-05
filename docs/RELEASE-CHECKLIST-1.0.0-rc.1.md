# CF-05 Release Checklist — 1.0.0-rc.1

## Source gates

- [x] CF05-FR-001–035 coding domains traced.
- [x] Runtime disabled by default and fail-closed.
- [x] Event/metric contracts versioned and immutable.
- [x] Privacy allowlists, pseudonymization, cohort suppression and sensitive-value rejection.
- [x] Dataset, lineage, pipeline, backfill/rebuild, report, export, experiment and decision contracts.
- [x] Deletion propagation, retention holds, expiry, provider-exit and restore job types.
- [x] Four review/fix records.
- [ ] Exact-head CI green.
- [ ] Deterministic package, checksum and SBOM.

## External acceptance gates

- [ ] Real cross-file contract fixtures.
- [ ] Hostinger fresh install and upgrade.
- [ ] Migration dry-run, shadow comparison and rollback rehearsal.
- [ ] Browser, RTL, keyboard, screen-reader and zoom acceptance.
- [ ] Load, penetration, backup/restore, provider-exit and deletion drills.
- [ ] Founder staging approval.
- [ ] Production deployment and monitored rollback window.

No unchecked external gate may be represented as complete by source code alone.
