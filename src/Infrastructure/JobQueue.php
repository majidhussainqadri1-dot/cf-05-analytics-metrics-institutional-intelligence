<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class JobQueue
{
    private const TYPES = [
        'pipeline.process_event',
        'snapshot.compute',
        'quality.run',
        'backfill.run',
        'export.build',
        'report.run',
        'deletion.apply',
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $type, array $payload, string $idempotencyKey, int $maxAttempts = 5, ?string $runAt = null): array|WP_Error
    {
        if (!in_array($type, self::TYPES, true) || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 1000) {
            return new WP_Error('smai_invalid_job', 'Job type or idempotency key is invalid.', ['status' => 400]);
        }
        if ($runAt !== null) {
            $scheduled = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $runAt, new \DateTimeZone('UTC'));
            if ($scheduled === false || $scheduled->format('Y-m-d H:i:s') !== $runAt) {
                return new WP_Error('smai_invalid_job_schedule', 'Job run time is invalid.', ['status' => 400]);
            }
        }
        $table = $this->db->table('jobs');
        $wpdb = $this->db->wpdb();
        $now = gmdate('Y-m-d H:i:s');
        $uuid = Uuid::v4();
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (job_uuid,job_type,state,payload_json,idempotency_key,attempts,max_attempts,next_run_at,created_at,updated_at) VALUES (%s,%s,'queued',%s,%s,0,%d,%s,%s,%s)",
            $uuid,
            $type,
            Json::encode($payload),
            hash('sha256', $idempotencyKey),
            max(1, min(20, $maxAttempts)),
            $runAt ?? $now,
            $now,
            $now
        ));
        if ($inserted === false) {
            return new WP_Error('smai_job_store_failed', 'Job could not be stored.', ['status' => 500]);
        }
        if ($inserted === 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT job_uuid,state FROM `{$table}` WHERE idempotency_key=%s",
                hash('sha256', $idempotencyKey)
            ), ARRAY_A);
            return ['job_uuid' => (string) ($existing['job_uuid'] ?? ''), 'state' => (string) ($existing['state'] ?? 'unknown'), 'duplicate' => true];
        }
        return ['job_uuid' => $uuid, 'state' => 'queued', 'duplicate' => false];
    }

    /** @return array<string,mixed>|null */
    public function claim(string $workerId, int $leaseSeconds = 120): ?array
    {
        $workerId = trim($workerId);
        if ($workerId === '' || strlen($workerId) > 100 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,99}$/', $workerId) !== 1) {
            return null;
        }
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + max(30, min(900, $leaseSeconds)));
        if ($wpdb->query('START TRANSACTION') === false) {
            return null;
        }
        try {
            $expired = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET state='dead_letter',error_code='lease_attempts_exhausted',error_message='Worker lease expired after the maximum attempts.',lease_owner=NULL,lease_until=NULL,updated_at=%s WHERE state='running' AND lease_until<%s AND attempts>=max_attempts",
                $now,
                $now
            ));
            if ($expired === false) { throw new \RuntimeException('Expired job fencing failed.'); }
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE ((state IN ('queued','retrying') AND next_run_at<=%s AND attempts<max_attempts) OR (state='running' AND lease_until<%s AND attempts<max_attempts)) ORDER BY next_run_at,id LIMIT 1 FOR UPDATE",
                $now,
                $now
            ), ARRAY_A);
            if (!is_array($row)) {
                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                }
                return null;
            }
            $where = ['id' => (int) $row['id'], 'state' => (string) $row['state']];
            if ((string) $row['state'] === 'running') {
                $where['lease_owner'] = (string) $row['lease_owner'];
                $where['lease_until'] = (string) $row['lease_until'];
            }
            $updated = $wpdb->update($table, [
                'state' => 'running',
                'lease_owner' => Text::truncate($workerId, 100),
                'lease_until' => $leaseUntil,
                'attempts' => (int) $row['attempts'] + 1,
                'updated_at' => $now,
            ], $where);
            if ($updated !== 1) {
                $wpdb->query('ROLLBACK');
                return null;
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return null;
            }
            $row['state'] = 'running';
            $row['lease_owner'] = $workerId;
            $row['lease_until'] = $leaseUntil;
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['payload'] = Json::object((string) $row['payload_json']);
            return $row;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return null;
        }
    }

    /** @param array<string,mixed> $result */
    public function complete(string $jobUuid, string $workerId, array $result = []): bool
    {
        $wpdb = $this->db->wpdb(); $table = $this->db->table('jobs'); $now = gmdate('Y-m-d H:i:s');
        return $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET state='completed',result_json=%s,lease_owner=NULL,lease_until=NULL,completed_at=%s,updated_at=%s WHERE job_uuid=%s AND state='running' AND lease_owner=%s AND lease_until>=%s",
            Json::encode($result), $now, $now, $jobUuid, $workerId, $now
        )) === 1;
    }

    public function fail(string $jobUuid, string $workerId, string $code, string $message): bool
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT attempts,max_attempts FROM `{$table}` WHERE job_uuid=%s AND state='running' AND lease_owner=%s AND lease_until>=%s",
            $jobUuid,
            $workerId,
            $now
        ), ARRAY_A);
        if (!is_array($row)) {
            return false;
        }
        $attempts = (int) $row['attempts'];
        $max = (int) $row['max_attempts'];
        $dead = $attempts >= $max;
        $delays = [60, 300, 1800, 7200, 43200];
        $delay = $delays[min(max(0, $attempts - 1), count($delays) - 1)];
        return $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET state=%s,error_code=%s,error_message=%s,next_run_at=%s,lease_owner=NULL,lease_until=NULL,updated_at=%s WHERE job_uuid=%s AND state='running' AND lease_owner=%s AND lease_until>=%s",
            $dead ? 'dead_letter' : 'retrying', Text::truncate($code, 100), Text::truncate($message, 500),
            gmdate('Y-m-d H:i:s', time() + $delay), $now, $jobUuid, $workerId, $now
        )) === 1;
    }
}
