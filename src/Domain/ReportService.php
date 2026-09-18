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
        $allowedDefinitionKeys = ['metrics','recipients','expires_at'];
        $cleanName = Text::truncate(trim(wp_strip_all_tags($name)), 190);
        $metrics = is_array($definition['metrics'] ?? null) ? array_values($definition['metrics']) : [];
        $recipients = is_array($definition['recipients'] ?? null) ? array_values($definition['recipients']) : [];
        if ($actorUserId < 1 || strlen($cleanName) < 3 || $metrics === [] || $recipients === [] || count($metrics) > 50 || count($recipients) > 50
            || array_diff(array_keys($definition), $allowedDefinitionKeys) !== []
            || (new \Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector())->violations([$cleanName, $definition]) !== []) {
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
                || array_diff(array_keys($metric), ['metric_id','metric_version','dimensions']) !== []
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($metric['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($metric['metric_version'] ?? '')) !== 1
                || !is_array($metric['dimensions'] ?? [])) {
                return new WP_Error('smai_invalid_report_metric', 'Report metric is invalid.', ['status' => 400]);
            }
            ksort($metric['dimensions']);
            $activeMetric = (new MetricCatalog($this->db))->active((string) $metric['metric_id'], (string) $metric['metric_version']);
            if ($activeMetric === null) { return new WP_Error('smai_report_metric_inactive', 'Report metric is not active.', ['status' => 409]); }
            $allowedDimensions = array_map('strval', (array) (($activeMetric['definition']['dimensions'] ?? [])));
            foreach ($metric['dimensions'] as $dimension => $value) {
                if (!in_array((string) $dimension, $allowedDimensions, true) || (!is_scalar($value) && $value !== null)) {
                    return new WP_Error('smai_report_dimension_denied', 'Report metric dimension is not approved.', ['status' => 400]);
                }
            }
            if (PrivacyQueryPolicy::violations((array) $activeMetric['definition'], $metric['dimensions']) !== []) {
                return new WP_Error('smai_report_privacy_policy_denied', 'Report metric slice violates the privacy policy.', ['status' => 403]);
            }
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
            if (!is_array($recipient)
                || array_diff(array_keys($recipient), ['type','user_id']) !== []
                || (string) ($recipient['type'] ?? '') !== 'user'
                || !array_key_exists('user_id', $recipient)
                || !is_int($recipient['user_id'])
                || $recipient['user_id'] < 1
                || !user_can($recipient['user_id'], 'smai_view_insights')) {
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
            $expiry = $this->strictTimestamp((string) $definition['expires_at']);
            if ($expiry === false || $expiry <= time() || $expiry > strtotime((string) $project['expires_at'])) {
                return new WP_Error('smai_invalid_report_expiry', 'Report expiry is invalid or exceeds project expiry.', ['status' => 400]);
            }
            $expires = gmdate('Y-m-d H:i:s', $expiry);
        }
        $definition['metrics'] = $metrics;
        $definition['recipients'] = $recipients;
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_report_transaction_failed', 'Report transaction could not start.', ['status'=>500]);
        }
        $ok = $wpdb->insert($this->db->table('reports'), [
            'report_uuid' => $uuid,
            'name' => $cleanName,
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
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_report_store_failed', 'Report could not be stored.', ['status' => 500]);
        }
        if (!$this->audit->logInOpenTransaction('report_created', 'report', $uuid, 'success', ['project_uuid' => $projectUuid], 'institutional_reporting', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_report_audit_failed', 'Report was not committed because audit evidence failed.', ['status'=>503]);
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_commit_failed','Report could not be committed.',['status'=>500]); }
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
        if (!$this->definitionStillGoverned($report, $project)) { return new WP_Error('smai_report_contract_drift', 'Report activation is blocked because its stored access, metric, privacy or recipient contract is no longer valid.', ['status' => 409]); }
        $schedule = $report['schedule_rrule'] === null ? null : (string) $report['schedule_rrule'];
        $next = $schedule === null ? null : gmdate('Y-m-d H:i:s', $this->nextTimestamp($schedule, time()));
        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_report_transaction_failed','Report activation transaction could not start.',['status'=>500]);}
        $updated = $wpdb->update($table, [
            'state' => 'active',
            'next_run_at' => $next,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) { $wpdb->query('ROLLBACK');
            return new WP_Error('smai_report_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        if ($schedule === null) {
            $job = (new JobQueue($this->db))->enqueue('report.run', [
                'report_uuid' => $uuid,
                'scheduled_for' => 'manual:' . $uuid,
            ], 'report|' . $uuid . '|manual');
            if (is_wp_error($job)) { $wpdb->query('ROLLBACK'); return $job; }
        }
        if(!$this->audit->logInOpenTransaction('report_activated', 'report', $uuid, 'success', ['schedule' => $schedule], 'institutional_reporting', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_audit_failed','Report activation was not committed because audit evidence failed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_report_commit_failed','Report activation could not be committed.',['status'=>500]);}
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
            $wpdb = $this->db->wpdb();
            if ($wpdb->query('START TRANSACTION') === false) {
                continue;
            }
            try {
                $claimed = $wpdb->update($table, [
                    'next_run_at' => $next,
                    'row_version' => (int) $report['row_version'] + 1,
                    'updated_at' => $now,
                ], ['id' => (int) $report['id'], 'state' => 'active', 'next_run_at' => $scheduledFor, 'row_version' => (int) $report['row_version']]);
                if ($claimed !== 1) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                $job = (new JobQueue($this->db))->enqueue('report.run', [
                    'report_uuid' => $report['report_uuid'],
                    'scheduled_for' => $scheduledFor,
                ], 'report|' . $report['report_uuid'] . '|' . $scheduledFor);
                if (is_wp_error($job)) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                $count++;
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
            }
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
            if (!is_array($recipient) || (int) ($recipient['user_id'] ?? 0) < 1) { continue; }
            $userId = (int) $recipient['user_id'];
            if (!user_can($userId, 'smai_view_insights')) { continue; }
            $runKey = hash_hmac('sha256', $uuid . '|' . $runRef . '|user:' . $userId, $this->privacyKey());
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
            $deliveryExpiry = time() + $ttl * HOUR_IN_SECONDS;
            if ($report['expires_at'] !== null) { $deliveryExpiry = min($deliveryExpiry, (int) strtotime((string) $report['expires_at'])); }
            $deliveryExpiry = min($deliveryExpiry, (int) strtotime((string) $project['expires_at']));
            if ($deliveryExpiry <= time()) { continue; }
            $expiresAt = gmdate('Y-m-d H:i:s', $deliveryExpiry);
            $wpdb = $this->db->wpdb();
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Report delivery transaction could not start.'); }
            $inserted = $wpdb->insert($this->db->table('report_deliveries'), [
                'delivery_uuid' => $deliveryUuid,
                'report_uuid' => $uuid,
                'run_key' => $runKey,
                'recipient_hash' => $this->recipientHash($userId),
                'channel' => 'file19',
                'state' => 'ready',
                'token_hash' => CryptoBox::tokenHash($token),
                'bundle_json' => Json::encode($bundle),
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1 || !$this->audit->logInOpenTransaction('report_delivery_created','report_delivery',$deliveryUuid,'success',['report_uuid'=>$uuid,'run_ref'=>$runRef],'institutional_reporting',null,(int)$report['owner_user_id'])) {
                $wpdb->query('ROLLBACK'); throw new \RuntimeException('Report delivery and audit evidence could not be stored.');
            }
            if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); throw new \RuntimeException('Report delivery could not be committed.'); }
            do_action('smai_report_delivery_requested', [
                'delivery_uuid' => $deliveryUuid,
                'report_uuid' => $uuid,
                'recipient_user_id' => $userId,
                'token' => $token,
                'expires_at' => gmdate('c', (int) strtotime($expiresAt)),
            ]);
            $deliveries[] = ['delivery_uuid' => $deliveryUuid, 'recipient_user_id' => $userId, 'status' => 'created'];
        }
        if (!$this->audit->log('scheduled_report_generated', 'report', $uuid, 'success', ['run_ref' => $runRef, 'metrics' => count($bundle['metrics']), 'deliveries' => count($deliveries)], 'institutional_reporting', null, (int) $report['owner_user_id'])) { throw new \RuntimeException('Scheduled report summary audit failed.'); }
        return ['report_uuid' => $uuid, 'run_ref' => $runRef, 'deliveries' => $deliveries, 'metric_count' => count($bundle['metrics'])];
    }

    /** @return array<string,mixed>|WP_Error */
    public function accessDelivery(string $deliveryUuid, string $token, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('report_deliveries');
        $delivery = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE delivery_uuid=%s AND state IN ('ready','sent')", $deliveryUuid), ARRAY_A);
        if (!is_array($delivery)
            || !hash_equals((string) $delivery['recipient_hash'], $this->recipientHash($actorUserId))
            || !hash_equals((string) $delivery['token_hash'], CryptoBox::tokenHash($token))
            || strtotime((string) $delivery['expires_at']) <= time()
            || $delivery['revoked_at'] !== null
            || !user_can($actorUserId, 'smai_view_insights')) {
            return new WP_Error('smai_report_delivery_unavailable', 'Report delivery is unavailable.', ['status' => 404]);
        }
        $report = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT project_uuid,state,expires_at FROM `{$this->db->table('reports')}` WHERE report_uuid=%s", $delivery['report_uuid']), ARRAY_A);
        $project = is_array($report) ? (new AccessProjectService($this->db))->get((string) $report['project_uuid']) : null;
        if (!is_array($report) || (string) $report['state'] !== 'active' || ($report['expires_at'] !== null && strtotime((string) $report['expires_at']) <= time()) || !is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_report_delivery_revoked', 'Report delivery is no longer authorized.', ['status' => 404]);
        }
        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_report_delivery_unavailable','Report delivery audit is unavailable.',['status'=>503]);}
        $marked=$wpdb->update($table, ['state' => 'sent', 'sent_at' => $delivery['sent_at'] ?? $this->db->now(), 'updated_at' => $this->db->now()], ['id' => (int) $delivery['id']]);
        if($marked===false || !$this->audit->logInOpenTransaction('report_delivery_accessed','report_delivery',$deliveryUuid,'success',['report_uuid'=>(string)$delivery['report_uuid']],'institutional_reporting',null,$actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_report_delivery_unavailable','Report delivery audit could not be committed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_report_delivery_unavailable','Report delivery could not be committed.',['status'=>503]);}
        return Json::object((string) $delivery['bundle_json']);
    }

    /** @param array<string,mixed> $dimensions @return array<string,mixed>|null */
    private function latestSnapshot(string $metricId, string $version, array $dimensions): ?array
    {
        ksort($dimensions);
        $metric = (new MetricCatalog($this->db))->active($metricId, $version);
        if ($metric === null || PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions) !== []) { return null; }
        $minimum = PrivacyQueryPolicy::effectiveMinimum((array) $metric['definition'], $dimensions, max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20)));
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' AND quality_status NOT IN ('suppressed','invalidated') ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1",
            $metricId,
            $version,
            hash('sha256', Json::canonical($dimensions))
        ), ARRAY_A);
        if (!is_array($row) || (int) $row['cohort_size'] < $minimum) {
            return null;
        }
        $disclosure = SnapshotDisclosurePolicy::evaluate(
            (string) $row['quality_status'],
            $row['data_through'] ?? null,
            Json::list((string) ($row['caveats_json'] ?? '[]')),
            (array) $metric['definition']
        );
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
            'quality_status' => $disclosure['quality_status'],
            'data_through' => $disclosure['data_through'],
            'freshness_seconds' => $disclosure['freshness_seconds'],
            'uncertainty' => Json::object((string) ($row['uncertainty_json'] ?? '{}')),
            'caveats' => $disclosure['caveats'],
        ];
    }

    private function privacyKey(): string
    {
        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            throw new \RuntimeException('Report pseudonym key is unavailable.');
        }
        return SMAI_PSEUDONYM_KEY;
    }

    private function recipientHash(int $userId): string
    {
        return hash_hmac('sha256', 'user:' . $userId, $this->privacyKey());
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
    /** @param array<string,mixed> $report @param array<string,mixed> $project */
    private function definitionStillGoverned(array $report, array $project): bool
    {
        $definition = Json::object((string) $report['definition_json']); $access = new AccessProjectService($this->db);
        $recipients = is_array($definition['recipients'] ?? null) ? $definition['recipients'] : [];
        if ($recipients === []) { return false; }
        foreach ($recipients as $recipient) { if (!is_array($recipient) || (string)($recipient['type']??'') !== 'user' || (int)($recipient['user_id']??0) < 1 || !user_can((int)$recipient['user_id'],'smai_view_insights')) { return false; } }
        $metrics = is_array($definition['metrics'] ?? null) ? $definition['metrics'] : [];
        if ($metrics === []) { return false; }
        foreach ($metrics as $metricSpec) {
            if (!is_array($metricSpec)) { return false; }
            $metricId=(string)($metricSpec['metric_id']??'');$metricVersion=(string)($metricSpec['metric_version']??'');$dimensions=is_array($metricSpec['dimensions']??null)?$metricSpec['dimensions']:[];
            $metric=(new MetricCatalog($this->db))->active($metricId,$metricVersion);
            if (!is_array($metric) || PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[] || !$access->authorize((string)$report['project_uuid'],(int)$report['owner_user_id'],'metric:'.$metricId.'@'.$metricVersion,[],(string)$project['purpose'])) { return false; }
        }
        return true;
    }

    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/', $value, $m) !== 1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) { return null; }
        $timestamp=strtotime($value); return $timestamp===false?null:$timestamp;
    }

}
