<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(plugin_basename(SMAI_FILE));
            wp_die(esc_html__('CF-05 requires PHP 8.1 or newer.', 'sabri-analytics-institutional-intelligence'));
        }
        if (!add_option('smai_activation_lock', ['started_at' => time()], '', false)) {
            $lock = get_option('smai_activation_lock');
            if (!is_array($lock) || time() - (int) ($lock['started_at'] ?? 0) < 600) {
                wp_die(esc_html__('CF-05 activation is already running.', 'sabri-analytics-institutional-intelligence'));
            }
            delete_option('smai_activation_lock');
            if (!add_option('smai_activation_lock', ['started_at' => time()], '', false)) {
                wp_die(esc_html__('CF-05 activation lock could not be acquired.', 'sabri-analytics-institutional-intelligence'));
            }
        }
        try {
            SchemaMigrator::migrate();
            self::defaults();
            self::capabilities();
            self::registerSchedule();
            self::schedules();
        } finally {
            delete_option('smai_activation_lock');
        }
    }

    public static function deactivate(): void
    {
        foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry'] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        delete_option('smai_activation_lock');
        delete_option('smai_schema_upgrade_lock');
    }

    private static function defaults(): void
    {
        add_option('smai_runtime_state', 'foundation_disabled', '', false);
        add_option('smai_activation_approved', '0', '', false);
        add_option('smai_activation_evidence_hash', '', '', false);
        add_option('smai_minimum_cohort', '20', '', false);
        add_option('smai_raw_retention_days', '30', '', false);
        add_option('smai_modeled_retention_days', '180', '', false);
        add_option('smai_quarantine_retention_days', '14', '', false);
        add_option('smai_export_ttl_hours', '24', '', false);
        add_option('smai_report_link_ttl_hours', '24', '', false);
        add_option('smai_max_export_rows', '10000', '', false);
        add_option('smai_worker_enabled', '0', '', false);
        add_option('smai_allowed_regions', ['PK'], '', false);
        add_option('smai_provider_exit_state', 'ready', '', false);
    }

    private static function capabilities(): void
    {
        $all = [
            'smai_view_insights','smai_manage_catalog','smai_approve_catalog','smai_manage_quality',
            'smai_manage_access','smai_ingest_events','smai_query_metrics','smai_export_metrics',
            'smai_manage_experiments','smai_manage_reports','smai_manage_backfills','smai_manage_providers',
            'smai_manage_deletions','smai_restore','smai_audit','smai_activate_runtime',
        ];
        $roles = [
            'smai_analyst' => [
                'label' => __('Institutional Analyst', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_query_metrics'],
            ],
            'smai_data_steward' => [
                'label' => __('Analytics Data Steward', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_manage_catalog','smai_manage_quality','smai_manage_backfills','smai_manage_providers','smai_ingest_events'],
            ],
            'smai_analytics_approver' => [
                'label' => __('Analytics Independent Approver', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_approve_catalog','smai_activate_runtime'],
            ],
            'smai_access_officer' => [
                'label' => __('Analytics Access and Reporting Officer', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_manage_access','smai_export_metrics','smai_manage_reports'],
            ],
            'smai_experiment_steward' => [
                'label' => __('Analytics Experiment Steward', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_manage_experiments'],
            ],
            'smai_auditor' => [
                'label' => __('Analytics Read-Only Auditor', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_audit'],
            ],
            'smai_recovery_operator' => [
                'label' => __('Analytics Recovery Operator', 'sabri-analytics-institutional-intelligence'),
                'caps' => ['read','smai_view_insights','smai_manage_deletions','smai_restore','smai_audit'],
            ],
        ];

        foreach ($roles as $slug => $definition) {
            $caps = array_fill_keys($definition['caps'], true);
            $role = get_role($slug);
            if (!$role) {
                $role = add_role($slug, (string) $definition['label'], $caps);
            }
            if ($role) {
                foreach ($definition['caps'] as $capability) {
                    $role->add_cap($capability);
                }
                foreach (array_diff($all, $definition['caps']) as $capability) {
                    $role->remove_cap($capability);
                }
            }
        }

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($all as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    public static function registerSchedule(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['smai_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every five minutes (CF-05)', 'sabri-analytics-institutional-intelligence'),
            ];
            return $schedules;
        });
    }

    private static function schedules(): void
    {
        $events = [
            ['smai_daily_retention', time() + HOUR_IN_SECONDS, 'daily'],
            ['smai_run_jobs', time() + 5 * MINUTE_IN_SECONDS, 'smai_five_minutes'],
            ['smai_schedule_reports', time() + 10 * MINUTE_IN_SECONDS, 'hourly'],
            ['smai_access_expiry', time() + 15 * MINUTE_IN_SECONDS, 'hourly'],
        ];
        foreach ($events as [$hook, $timestamp, $recurrence]) {
            if (!wp_next_scheduled($hook)) {
                $result = wp_schedule_event($timestamp, $recurrence, $hook, [], true);
                if (is_wp_error($result)) {
                    throw new \RuntimeException('CF-05 schedule creation failed: ' . $hook);
                }
            }
        }
    }
}
