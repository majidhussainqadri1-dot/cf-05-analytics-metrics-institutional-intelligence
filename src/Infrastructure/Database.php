<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use wpdb;

final class Database
{
    private wpdb $wpdb;
    private string $prefix;

    private const TABLES = [
        'event_schemas', 'events', 'quarantine', 'metrics', 'metric_snapshots',
        'access_projects', 'audit_log', 'audit_state', 'governance_transitions',
        'deletion_jobs', 'quality_issues', 'exports', 'ingestion_nonces',
        'datasets', 'dataset_builds', 'dataset_rows', 'lineage_edges',
        'quality_rules', 'quality_results', 'jobs', 'checkpoints', 'backfills',
        'providers', 'reports', 'report_deliveries', 'narratives', 'experiments',
        'experiment_facts', 'experiment_analyses', 'decision_records',
        'export_payloads', 'restore_points', 'deletion_reconciliations',
        'rate_limits', 'idempotency_keys', 'dashboard_definitions', 'dashboard_widgets',
        'future_features', 'future_runs', 'analytics_incidents', 'scenario_models',
        'research_workspaces', 'intelligence_alerts', 'transparency_records', 'privacy_budgets',
    ];

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'smai_';
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    public function table(string $name): string
    {
        if (!in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unknown CF-05 table.');
        }
        return $this->prefix . $name;
    }

    /** @return array<string,string> */
    public function tables(): array
    {
        $out = [];
        foreach (self::TABLES as $name) {
            $out[$name] = $this->table($name);
        }
        return $out;
    }

    public function exists(string $name): bool
    {
        $table = $this->table($name);
        $found = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return is_string($found) && hash_equals($table, $found);
    }

    /**
     * A failed COUNT or malformed numeric result is Unknown, not zero.
     * Health/assurance callers must distinguish an unavailable table reading.
     */
    public function count(string $name): ?int
    {
        $table = $this->table($name);
        $value = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ((!is_string($value) && !is_int($value))
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $value) !== 1) {
            return null;
        }
        $parsed = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $parsed === false ? null : $parsed;
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
