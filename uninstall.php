<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Governance, audit, metric and derivative data are retained by default.
// Destructive purge requires a separate owner-approved, retention-aware,
// legal-hold-aware and provider-reconciled operational procedure.
foreach ([
    'smai_runtime_state', 'smai_activation_approved', 'smai_activation_evidence_hash',
    'smai_minimum_cohort', 'smai_raw_retention_days', 'smai_modeled_retention_days',
    'smai_quarantine_retention_days', 'smai_export_ttl_hours', 'smai_report_link_ttl_hours',
    'smai_max_export_rows', 'smai_worker_enabled', 'smai_allowed_regions',
    'smai_provider_exit_state',
] as $option) {
    delete_option($option);
}
foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry'] as $hook) {
    wp_clear_scheduled_hook($hook);
}
