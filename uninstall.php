<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Governance, audit, metric and derivative DATA are retained by default.
// Destructive table/data purge requires a separate owner-approved,
// retention-aware, legal-hold-aware and provider-reconciled procedure.
// Runtime configuration, schedules, plugin-defined roles and capabilities are
// removed so an uninstalled plugin leaves no executable privilege surface.
foreach ([
    'smai_runtime_state', 'smai_activation_approved', 'smai_activation_evidence_hash', 'smai_activation_request',
    'smai_activation_lock', 'smai_schema_upgrade_lock', 'smai_schema_migration_error', 'smai_experiment_rebuild_required',
    'smai_minimum_cohort', 'smai_raw_retention_days', 'smai_modeled_retention_days', 'smai_quarantine_retention_days', 'smai_deletion_slo_hours',
    'smai_future_run_retention_days', 'smai_future_scenario_retention_days', 'smai_future_alert_retention_days', 'smai_future_incident_retention_days',
    'smai_export_ttl_hours', 'smai_report_link_ttl_hours', 'smai_max_export_rows', 'smai_worker_enabled', 'smai_allowed_regions', 'smai_provider_exit_state',
    'smai_future40_state', 'smai_future40_approved', 'smai_future40_evidence_hash', 'smai_future40_activation_request', 'smai_future40_approved_by', 'smai_future40_approved_at',
] as $option) {
    delete_option($option);
}

foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry','smai_future_intelligence_tick'] as $hook) {
    wp_clear_scheduled_hook($hook);
}

$capabilities = [
    'smai_view_insights','smai_manage_catalog','smai_approve_catalog','smai_manage_quality',
    'smai_manage_access','smai_ingest_events','smai_query_metrics','smai_export_metrics',
    'smai_manage_experiments','smai_manage_reports','smai_manage_backfills','smai_manage_providers',
    'smai_manage_deletions','smai_restore','smai_audit','smai_activate_runtime',
    'smai_manage_future_intelligence','smai_run_future_intelligence','smai_approve_future_intelligence','smai_view_transparency',
];
$administrator = get_role('administrator');
if ($administrator) {
    foreach ($capabilities as $capability) {
        $administrator->remove_cap($capability);
    }
}
foreach (['smai_analyst','smai_data_steward','smai_analytics_approver','smai_access_officer','smai_experiment_steward','smai_auditor','smai_recovery_operator'] as $role) {
    remove_role($role);
}
