<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\CryptoBox;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class ReportService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function create(string $name, string $projectUuid, array $definition, ?string $schedule, int $actorUserId): array|WP_Error
    {
        $metrics = is_array($definition['metrics'] ?? null) ? array_values($definition['metrics']) : [];
        $recipients = is_array($definition['recipients'] ?? null) ? array_values($definition['recipients']) : [];
        if ($actorUserId < 1 || strlen(trim($name)) < 3 || $metrics === [] || $recipients === [] || count($metrics) > 50 || count($recipients) > 50) {
            return new WP_Error('smai_invalid_report', 'Report definition is incomplete.', ['status' => 400]);
        }
        $access = new AccessProjectService($this->db);
        $project = $access->get($projectUuid);
        if (!is_array($project) || (string) $project['state'] !== 'active' || (int) $project['owner_user_id'] !== $actorUserId || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_report_project_inactive', 'Access project is inactive.', ['status' => 403]);
        }
        $seen = [];
        foreach ($metrics as &$metric) {
            if (!is_array($metric)
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($metric['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($metric['metric_version'] ?? '')) !== 1
                || !is_array($metric['dimensions'] ?? [])) {
                return new WP_Error('smai_invalid_report_metric', 'Report metric is invalid.', ['status' => 400]);
            }
            ksort($metric['dimensions']);
            $datasetRef = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];
            if (!$access->authorize($projectUuid, $actorUserId, $datasetRef, [])) {
                return new WP_Error('smai_report_access_denied', 'Access project does not authorize a report metric.', ['status' => 403]);
            }
            $key = $datasetRef . '|' . hash('sha256', Json::canonical($metric['dimensions']));
            if (isset($seen[$key])) {
                return new WP_Error('smai_duplicate_report_metric', 'Report contains a duplicate metric slice.', ['status' => 400]);
            }
            $seen[$key] = true;
        }
        unset($metric);
        $recipientIds = [];
        foreach ($recipients as $recipient) {
            if (!is_array($recipient) || (string) ($recipient['type'] ?? '') !== 'user' || (int) ($recipient['user_id'] ?? 0) < 1 || !user_can((int) $recipient['user_id'], 'smai_view_insights')) {
                return new WP_Error('smai_invalid_report_recipient', 'Report recipient is invalid.', ['status' => 400]);
            }
            $recipientIds[(int) $recipient['user_id']] = true;
        }
        if (count($recipientIds) !== count($recipients)) {
            return new WP_Error('smai_duplicate_report_recipient', 'Report recipients must be unique.', ['status' => 400]);
        }
        if ($schedule !== null && !in_array($schedule, ['hourly','daily','weekly','monthly'], true)) {
            return new WP_Error('smai_invalid_report_schedule', 'Report schedule is invalid.', ['status' => 400]);
        }
        $expires = null;
        if (!empty($definition['expires_at'])) {
            $expiry = strtotime((string) $definition['expires_at']);
            if ($expiry === false || $expiry <= time() || $expiry > strtotime((string) $project['expires_at'])) {
                return new WP_Error('smai_invalid_report_expiry', 'Report expiry is invalid or exceeds project expiry.', ['status' => 400]);
            }
            $expires = gmdate('Y-m-d H:i:s', $expiry);
        }
        $definition['metrics'] = $metrics;
        $definition['recipients'] = $recipients;
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('reports'), [
            'report_uuid' => $uuid,
            'name' => Text::truncate(trim(wp_strip_all_tags($name)), 190),
            'state' => 'draft',
            'owner_user_id' => $actorUserId,
            'project_uuid' => $projectUuid,
            'definition_json' => Json::canonical($definition),
            'schedule_rrule' => $schedule,
            'next_run_at' => null,
            'expires_at' => $expires,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_report_store_failed', 'Report could not be stored.', ['status' => 500]);
        }
        $this->audit->log('report_created', 'report', $uuid, 'success', ['project_uuid' => $projectUuid], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => 'draft', 'row_version' => 1];
    }

    public function activate(string $uuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('reports');
        $report = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE report_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($report) || (string) $report['state'] !== 'draft' || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_stale', 'Report is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $report['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Report owner cannot approve their own report.', ['status' => 403]);
        }
        $project = (new AccessProjectService($this->db))->get((string) $report['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_report_project_inactive', 'Report access project is inactive.', ['status' => 409]);
        }
        $schedule = $report['schedule_rrule'] === null ? null : (string) $report['schedule_rrule'];
        $next = $schedule === null ? null : gmdate('Y-m-d H:i:s', $this->nextTimestamp($schedule, time()));
        $updated = $this->db->wpdb()->update($table, [
            'state' => 'active',
            'next_run_at' => $next,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        if ($schedule === null) {
            $job = (new JobQueue($this->db))->enqueue('report.run', [
                'report_uuid' => $uuid,
                'scheduled_for' => 'manual:' . $uuid,
            ], 'report|' . $uuid . '|manual');
            if (is_wp_error($job)) {
                $this->db->wpdb()->update($table, ['state' => 'paused', 'updated_at' => $this->db->now()], ['id' => (int) $report['id'], 'state' => 'active']);
                return $job;
            }
        }
        $this->audit->log('report_activated', 'report', $uuid, 'success', ['schedule' => $schedule], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1, 'next_run_at' => $next];
    }

    public function scheduleDue(): int
    {
        $table = $this->db->table('reports');
        $now = $this->db->now();
        $reports = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT id,report_uuid,schedule_rrule,next_run_at,row_version FROM `{$table}` WHERE state='active' AND schedule_rrule IS NOT NULL AND next_run_at<=%s AND (expires_at IS NULL OR expires_at>%s) ORDER BY next_run_at LIMIT 100",
            $now,
            $now
        ), ARRAY_A);
        $count = 0;
        foreach (is_array($reports) ? $reports : [] as $report) {
            $scheduledFor = (string) $report['next_run_at'];
            $next = gmdate('Y-m-d H:i:s', $this->nextTimestamp((string) $report['schedule_rrule'], (int) strtotime($scheduledFor)));
            $claimed = $this->db->wpdb()->update($table, [
                'next_run_at' => $next,
                'row_version' => (int) $report['row_version'] + 1,
                'updated_at' => $now,
            ], ['id' => (int) $report['id'], 'state' => 'active', 'next_run_at' => $scheduledFor, 'row_version' => (int) $report['row_version']]);
            if ($claimed !== 1) {
                continue;
            }
            $job = (new JobQueue($this->db))->enqueue('report.run', [
                'report_uuid' => $report['report_uuid'],
                'scheduled_for' => $scheduledFor,
            ], 'report|' . $report['report_uuid'] . '|' . $scheduledFor);
            if (is_wp_error($job)) {
                $this->db->wpdb()->update($table, ['next_run_at' => $scheduledFor, 'updated_at' => $now], ['id' => (int) $report['id'], 'next_run_at' => $next]);
                continue;
            }
            $count++;
        }
        return $count;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $uuid = (string) ($payload['report_uuid'] ?? '');
        $runRef = Text::truncate((string) ($payload['scheduled_for'] ?? 'manual:' . $uuid), 190);
        $table = $this->db->table('reports');
        $report = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE report_uuid=%s AND state='active'", $uuid), ARRAY_A);
        if (!is_array($report) || ($report['expires_at'] !== null && strtotime((string) $report['expires_at']) <= time())) {
            throw new \RuntimeException('Report is unavailable.');
        }
        $project = (new AccessProjectService($this->db))->get((string) $report['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            throw new \RuntimeException('Report access project is inactive.');
        }
        $definition = Json::object((string) $report['definition_json']);
        $bundle = [
            'report_uuid' => $uuid,
            'run_ref' => $runRef,
            'name' => $report['name'],
            'generated_at' => gmdate('c'),
            'metrics' => [],
            'caveats' => [],
        ];
        foreach ((array) ($definition['metrics'] ?? []) as $metricSpec) {
            if (!is_array($metricSpec)) {
                continue;
            }
            $metricId = (string) ($metricSpec['metric_id'] ?? '');
            $metricVersion = (string) ($metricSpec['metric_version'] ?? '');
            $datasetRef = 'metric:' . $metricId . '@' . $metricVersion;
            $authorized = (new AccessProjectService($this->db))->authorize((string) $report['project_uuid'], (int) $report['owner_user_id'], $datasetRef, [], (string) $project['purpose']);
            $snapshot = $authorized && (new MetricCatalog($this->db))->active($metricId, $metricVersion) !== null
                ? $this->latestSnapshot($metricId, $metricVersion, is_array($metricSpec['dimensions'] ?? null) ? $metricSpec['dimensions'] : [])
                : null;
            $bundle['metrics'][] = $snapshot ?? [
                'metric_id' => $metricSpec['metric_id'] ?? null,
                'metric_version' => $metricSpec['metric_version'] ?? null,
                'status' => 'unavailable',
                'caveats' => ['No approved non-suppressed snapshot is available.'],
            ];
        }
        $ttl = max(1, min(168, (int) get_option('smai_report_link_ttl_hours', 24)));
        $deliveries = [];
        foreach ((array) ($definition['recipients'] ?? []) as $recipient) {
            if (!is_array($recipient) || (int) ($recipient['user_id'] ?? 0) < 1) {
                continue;
            }
            $userId = (int) $recipient['user_id'];
            $runKey = hash('sha256', $uuid . '|' . $runRef . '|user:' . $userId);
            $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                "SELECT delivery_uuid,state,expires_at FROM `{$this->db->table('report_deliveries')}` WHERE run_key=%s",
                $runKey
            ), ARRAY_A);
            if (is_array($existing)) {
                $deliveries[] = ['delivery_uuid' => $existing['delivery_uuid'], 'recipient_user_id' => $userId, 'status' => 'unchanged'];
                continue;
            }
            $token = bin2hex(random_bytes(32));
            $deliveryUuid = Uuid::v4();
            $now = $this->db->now();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl * HOUR_IN_SECONDS);
            $inserted = $this->db->wpdb()->insert($this->db->table('report_deliveries'), [
                'delivery_uuid' => $deliveryUuid,
                'report_uuid' => $uuid,
                'run_key' => $runKey,
                'recipient_hash' => hash('sha256', 'user:' . $userId),
                'channel' => 'file19',
                'state' => 'ready',
                'token_hash' => CryptoBox::tokenHash($token),
                'bundle_json' => Json::encode($bundle),
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                throw new \RuntimeException('Report delivery could not be stored.');
            }
            do_action('smai_report_delivery_requested', [
                'delivery_uuid' => $deliveryUuid,
                'report_uuid' => $uuid,
                'recipient_user_id' => $userId,
                'token' => $token,
                'expires_at' => gmdate('c', (int) strtotime($expiresAt)),
            ]);
            $deliveries[] = ['delivery_uuid' => $deliveryUuid, 'recipient_user_id' => $userId, 'status' => 'created'];
        }
        $this->audit->log('scheduled_report_generated', 'report', $uuid, 'success', ['run_ref' => $runRef, 'metrics' => count($bundle['metrics']), 'deliveries' => count($deliveries)], 'institutional_reporting', null, (int) $report['owner_user_id']);
        return ['report_uuid' => $uuid, 'run_ref' => $runRef, 'deliveries' => $deliveries, 'metric_count' => count($bundle['metrics'])];
    }

    /** @return array<string,mixed>|WP_Error */
    public function accessDelivery(string $deliveryUuid, string $token, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('report_deliveries');
        $delivery = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE delivery_uuid=%s AND state IN ('ready','sent')", $deliveryUuid), ARRAY_A);
        if (!is_array($delivery)
            || !hash_equals((string) $delivery['recipient_hash'], hash('sha256', 'user:' . $actorUserId))
            || !hash_equals((string) $delivery['token_hash'], CryptoBox::tokenHash($token))
            || strtotime((string) $delivery['expires_at']) <= time()
            || $delivery['revoked_at'] !== null
            || !user_can($actorUserId, 'smai_view_insights')) {
            return new WP_Error('smai_report_delivery_unavailable', 'Report delivery is unavailable.', ['status' => 404]);
        }
        $report = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT project_uuid,state FROM `{$this->db->table('reports')}` WHERE report_uuid=%s", $delivery['report_uuid']), ARRAY_A);
        $project = is_array($report) ? (new AccessProjectService($this->db))->get((string) $report['project_uuid']) : null;
        if (!is_array($report) || (string) $report['state'] !== 'active' || !is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_report_delivery_revoked', 'Report delivery is no longer authorized.', ['status' => 404]);
        }
        $this->db->wpdb()->update($table, ['state' => 'sent', 'sent_at' => $delivery['sent_at'] ?? $this->db->now(), 'updated_at' => $this->db->now()], ['id' => (int) $delivery['id']]);
        return Json::object((string) $delivery['bundle_json']);
    }

    /** @param array<string,mixed> $dimensions @return array<string,mixed>|null */
    private function latestSnapshot(string $metricId, string $version, array $dimensions): ?array
    {
        ksort($dimensions);
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' AND quality_status<>'suppressed' ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1",
            $metricId,
            $version,
            hash('sha256', Json::canonical($dimensions))
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return [
            'metric_id' => $row['metric_id'],
            'metric_version' => $row['metric_version'],
            'window_start' => gmdate('c', (int) strtotime((string) $row['window_start'])),
            'window_end' => gmdate('c', (int) strtotime((string) $row['window_end'])),
            'dimensions' => Json::object((string) $row['dimensions_json']),
            'value' => $row['value_decimal'] === null ? null : (float) $row['value_decimal'],
            'numerator' => $row['numerator_decimal'] === null ? null : (float) $row['numerator_decimal'],
            'denominator' => $row['denominator_decimal'] === null ? null : (float) $row['denominator_decimal'],
            'cohort_size' => (int) $row['cohort_size'],
            'quality_status' => $row['quality_status'],
            'data_through' => $row['data_through'] ? gmdate('c', (int) strtotime((string) $row['data_through'])) : null,
            'uncertainty' => Json::object((string) ($row['uncertainty_json'] ?? '{}')),
            'caveats' => Json::list((string) ($row['caveats_json'] ?? '[]')),
        ];
    }

    private function nextTimestamp(string $schedule, int $from): int
    {
        return match ($schedule) {
            'hourly' => $from + HOUR_IN_SECONDS,
            'daily' => $from + DAY_IN_SECONDS,
            'weekly' => $from + WEEK_IN_SECONDS,
            'monthly' => strtotime('+1 month', $from) ?: $from + 30 * DAY_IN_SECONDS,
            default => $from + DAY_IN_SECONDS,
        };
    }
}
