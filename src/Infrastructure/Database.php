<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use wpdb;

final class Database
{
    private wpdb $wpdb;
    private string $prefix;

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
        $allowed = [
            'event_schemas', 'events', 'quarantine', 'metrics', 'metric_snapshots',
            'access_projects', 'audit_log', 'audit_state', 'governance_transitions', 'deletion_jobs', 'quality_issues', 'exports', 'ingestion_nonces',
        ];
        if (!in_array($name, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown CF-05 table.');
        }
        return $this->prefix . $name;
    }

    /** @return array<string,string> */
    public function tables(): array
    {
        $out = [];
        foreach (['event_schemas','events','quarantine','metrics','metric_snapshots','access_projects','audit_log','audit_state','governance_transitions','deletion_jobs','quality_issues','exports','ingestion_nonces'] as $name) {
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

    public function count(string $name): int
    {
        $table = $this->table($name);
        $value = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return max(0, (int) $value);
    }
}
