# Sequential Review Round 65 — Schema migration serialization and operational-option cleanup

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA must pass before Round 66 begins.

## Frozen defect ledger (2)
1. Schema migration callers did not share one migration lock: plugin boot used `smai_schema_upgrade_lock`, activation used a separate activation lock, and Safe Repair could call the migrator without the schema lock. Concurrent requests could therefore execute the migration routine simultaneously.
2. Health governance reads `smai_deletion_slo_hours` as a CF-05 operational option, but activation did not persist its default and uninstall did not remove it, creating operational-option lifecycle drift.

## Corrections
- Moved the stale-safe `smai_schema_upgrade_lock` into `SchemaMigrator::migrate()` itself so activation, runtime upgrade and Safe Repair all serialize on the same migration lock.
- Removed the duplicate caller-level schema lock from `Plugin::upgradeIfNeeded()`.
- Added the 24-hour deletion-SLO default during activation and removed that option during uninstall.

## Truth boundary
Repository-source review and automated QA only; this does not establish the live database schema or migration state.
