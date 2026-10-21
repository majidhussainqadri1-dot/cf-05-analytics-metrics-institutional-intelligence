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
        $schemaVersion = (string) get_option('smai_schema_version', 'unknown');
        $cron = [
            'retention' => wp_next_scheduled('smai_daily_retention') ?: null,
            'jobs' => wp_next_scheduled('smai_run_jobs') ?: null,
            'reports' => wp_next_scheduled('smai_schedule_reports') ?: null,
            'access_expiry' => wp_next_scheduled('smai_access_expiry') ?: null,
            'future_intelligence' => wp_next_scheduled('smai_future_intelligence_tick') ?: null,
        ];
        $audit = (new AuditVerifier($this->db))->verify(10000);
        $healthy = $missing === []
            && hash_equals(SMAI_SCHEMA_VERSION, $schemaVersion)
            && !in_array(null, $cron, true)
            && (($audit['status'] ?? '') === 'verified');
        return [
            'status' => $healthy ? 'healthy' : 'needs_repair',
            'missing_tables' => $missing,
            'schema_version' => $schemaVersion,
            'expected_schema_version' => SMAI_SCHEMA_VERSION,
            'cron' => $cron,
            'audit' => $audit,
            'runtime_state' => RuntimeGate::state(),
            'checked_at' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function safeRepair(): array
    {
        SchemaMigrator::migrate();
        $this->ensureSchedule('smai_daily_retention', time()+HOUR_IN_SECONDS, 'daily');
        $this->ensureSchedule('smai_run_jobs', time()+5*MINUTE_IN_SECONDS, 'smai_five_minutes');
        $this->ensureSchedule('smai_schedule_reports', time()+10*MINUTE_IN_SECONDS, 'hourly');
        $this->ensureSchedule('smai_access_expiry', time()+15*MINUTE_IN_SECONDS, 'hourly');
        $this->ensureSchedule('smai_future_intelligence_tick', time()+20*MINUTE_IN_SECONDS, 'hourly');
        $now = $this->db->now();
        $recovered=$this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('jobs')}` SET state='retrying',lease_owner=NULL,lease_until=NULL,next_run_at=%s,updated_at=%s WHERE state='running' AND lease_until<%s AND attempts < max_attempts",$now,$now,$now));
        if($recovered===false)throw new \RuntimeException('Expired job lease repair failed.');
        $dead=$this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('jobs')}` SET state='dead_letter',lease_owner=NULL,lease_until=NULL,error_code='lease_exhausted',error_message='Expired running lease exhausted maximum attempts.',updated_at=%s WHERE state='running' AND lease_until<%s AND attempts >= max_attempts",$now,$now));
        if($dead===false)throw new \RuntimeException('Exhausted lease fail-closed repair failed.');
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
            $job = $queue->enqueue('deletion.apply', ['deletion_job_uuid' => (string) $deletion['job_uuid'], 'actor_type' => 'system'], 'deletion-repair|' . (string) $deletion['job_uuid'] . '|' . gmdate('Y-m-d-H'));
            if (!is_wp_error($job)) {
                $requeuedDeletions++;
            }
        }
        $result = $this->check();
        $result['status'] = ($result['status'] ?? '') === 'healthy' ? 'repaired' : 'incomplete';
        $result['requeued_unprocessed_events'] = $requeued;
        $result['requeued_deletion_jobs'] = $requeuedDeletions;
        if (!(new AuditLogger($this->db))->log(
            'analytics_safe_repair_completed',
            'repair_run',
            gmdate('Y-m-d-H'),
            'success',
            [
                'recovered_expired_leases' => (int) $recovered,
                'dead_lettered_exhausted_leases' => (int) $dead,
                'requeued_unprocessed_events' => $requeued,
                'requeued_deletion_jobs' => $requeuedDeletions,
            ],
            'operational_repair',
            null,
            null,
            'system'
        )) {
            throw new \RuntimeException('Safe-repair audit evidence could not be recorded.');
        }
        return $result;
    }
    private function ensureSchedule(string $hook,int $timestamp,string $recurrence):void
    {
        if(wp_next_scheduled($hook))return;$result=wp_schedule_event($timestamp,$recurrence,$hook,[],true);if(is_wp_error($result)||$result===false)throw new \RuntimeException('CF-05 repair could not create schedule: '.$hook);
    }

}
