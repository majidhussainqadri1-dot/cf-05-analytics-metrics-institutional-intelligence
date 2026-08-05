=== Sabri Analytics, Metrics and Institutional Intelligence ===
Contributors: sabri-platform
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: Proprietary

Conditional, privacy-safe analytics governance and institutional intelligence foundation for the Sabri Social Homeopathy Platform.

== Description ==

CF-05 provides a disabled-by-default foundation for versioned event contracts, privacy-safe ingestion, metric definitions, aggregate snapshots, data quality, access governance, audit evidence and institutional insight surfaces.

It does not own user identity, domain entities, clinical records, private messages, payment ledgers, search ranking, recommendations or human decisions.

== Installation ==

1. Install on staging only.
2. Activate the plugin.
3. Keep runtime state at `foundation_disabled` or `catalog_only` until all activation gates are approved.
4. Configure service and pseudonymization secrets in `wp-config.php`; never store them in the repository.
5. Complete contract, privacy, security, migration, rollback and Founder acceptance evidence before enabling ingestion.

== Changelog ==

= 0.1.0 =
* Initial governed foundation: schema registry, privacy gateway, event quarantine, metric catalog, aggregate query policy, health reporting, admin surfaces and retention runner.
