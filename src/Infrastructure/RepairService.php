<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class RepairService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function check(): array
    {
        $missing = [];
        foreach ($this->db->tables() as $name => $table) {
            if (!$this->db->exists($name)) {
                $missing[] = $name;
            }
        }
        return [
            'missing_tables' => $missing,
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'expected_schema_version' => SMAI_SCHEMA_VERSION,
            'cron' => [
                'retention' => wp_next_scheduled('smai_daily_retention') ?: null,
                'jobs' => wp_next_scheduled('smai_run_jobs') ?: null,
                'reports' => wp_next_scheduled('smai_schedule_reports') ?: null,
                'access_expiry' => wp_next_scheduled('smai_access_expiry') ?: null,
            ],
            'audit' => (new AuditVerifier($this->db))->verify(10000),
            'runtime_state' => RuntimeGate::state(),
            'checked_at' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function safeRepair(): array
    {
        SchemaMigrator::migrate();
        if (!wp_next_scheduled('smai_daily_retention')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'smai_daily_retention');
        }
        if (!wp_next_scheduled('smai_run_jobs')) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'smai_five_minutes', 'smai_run_jobs');
        }
        if (!wp_next_scheduled('smai_schedule_reports')) {
            wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'smai_schedule_reports');
        }
        if (!wp_next_scheduled('smai_access_expiry')) {
            wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, 'hourly', 'smai_access_expiry');
        }
        $now = $this->db->now();
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('jobs')}` SET state='retrying',lease_owner=NULL,lease_until=NULL,next_run_at=%s,updated_at=%s WHERE state='running' AND lease_until<%s",
            $now,
            $now,
            $now
        ));
        $eventTable = $this->db->table('events');
        $unprocessed = $this->db->wpdb()->get_col($this->db->wpdb()->prepare(
            "SELECT event_id FROM `{$eventTable}` WHERE processed_at IS NULL AND created_at<%s ORDER BY id LIMIT 500",
            gmdate('Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS)
        ));
        $requeued = 0;
        $queue = new JobQueue($this->db);
        foreach (is_array($unprocessed) ? $unprocessed : [] as $eventId) {
            $job = $queue->enqueue('pipeline.process_event', ['event_id' => (string) $eventId], 'pipeline-repair|' . (string) $eventId);
            if (!is_wp_error($job)) {
                $requeued++;
            }
        }
        $deletions = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT job_uuid FROM `{$this->db->table('deletion_jobs')}` WHERE state IN ('requested','retrying') AND (next_retry_at IS NULL OR next_retry_at<=%s) ORDER BY id LIMIT 100",
            $now
        ), ARRAY_A);
        $requeuedDeletions = 0;
        foreach (is_array($deletions) ? $deletions : [] as $deletion) {
            $job = $queue->enqueue('deletion.apply', ['deletion_job_uuid' => (string) $deletion['job_uuid']], 'deletion-repair|' . (string) $deletion['job_uuid'] . '|' . gmdate('Y-m-d-H'));
            if (!is_wp_error($job)) {
                $requeuedDeletions++;
            }
        }
        $result = $this->check();
        $result['requeued_unprocessed_events'] = $requeued;
        $result['requeued_deletion_jobs'] = $requeuedDeletions;
        return $result;
    }
}
