<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\CryptoBox;
use Sabri\AnalyticsIntelligence\Infrastructure\CsvSafe;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class ExportService
{
    private const COLUMNS = ['metric_id','metric_version','window_start','window_end','dimensions','value','numerator','denominator','cohort_size','quality_status','data_through','caveats'];

    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function request(string $projectUuid, string $purpose, array $definition, int $actorUserId): array|WP_Error
    {
        if (!$this->keyConfigured()) {
            return new WP_Error('smai_export_key_missing', 'Secure export encryption is not configured.', ['status' => 503]);
        }
        $cleanPurpose = Text::truncate(trim(wp_strip_all_tags($purpose)), 190);
        if ($actorUserId < 1 || !$this->validUuid($projectUuid) || strlen($cleanPurpose) < 8
            || (new SensitiveValueDetector())->violations([$cleanPurpose, $definition]) !== []
            || array_diff(array_keys($definition), ['source_type','metric_id','metric_version','columns','row_limit','window_start','window_end']) !== []) {
            return new WP_Error('smai_invalid_export_definition', 'Export request is invalid.', ['status' => 400]);
        }
        if ((string) ($definition['source_type'] ?? '') !== 'metric_snapshots') {
            return new WP_Error('smai_export_source_denied', 'Only approved aggregate metric snapshots can be exported.', ['status' => 403]);
        }
        $metricId = (string) ($definition['metric_id'] ?? '');
        $metricVersion = (string) ($definition['metric_version'] ?? '');
        $columns = is_array($definition['columns'] ?? null) ? array_values(array_unique(array_map('strval', $definition['columns']))) : [];
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $metricVersion) !== 1
            || $columns === [] || count($columns) > count(self::COLUMNS) || array_diff($columns, self::COLUMNS) !== []) {
            return new WP_Error('smai_invalid_export_definition', 'Export definition is invalid.', ['status' => 400]);
        }
        sort($columns, SORT_STRING);
        $metric = (new MetricCatalog($this->db))->active($metricId, $metricVersion);
        if ($metric === null) {
            return new WP_Error('smai_export_metric_unavailable', 'Export metric is not active.', ['status' => 409]);
        }
        $windowStart = $this->optionalDate($definition['window_start'] ?? null);
        $windowEnd = $this->optionalDate($definition['window_end'] ?? null);
        if (($definition['window_start'] ?? null) !== null && $windowStart === null
            || ($definition['window_end'] ?? null) !== null && $windowEnd === null
            || ($windowStart !== null && $windowEnd !== null && $windowStart >= $windowEnd)) {
            return new WP_Error('smai_invalid_export_window', 'Export window is invalid.', ['status' => 400]);
        }
        $datasetRef = 'metric:' . $metricId . '@' . $metricVersion;
        if (!(new AccessProjectService($this->db))->authorize($projectUuid, $actorUserId, $datasetRef, $columns, $cleanPurpose)) {
            return new WP_Error('smai_export_access_denied', 'Access project does not authorize this export.', ['status' => 403]);
        }
        if (array_key_exists('row_limit', $definition) && (!is_int($definition['row_limit']) || $definition['row_limit'] < 1)) { return new WP_Error('smai_invalid_export_definition', 'Export row_limit must be a positive JSON integer.', ['status' => 400]); }
        $configuredRowLimit = max(1, min(100000, (int) get_option('smai_max_export_rows', 10000)));
        $requestedRowLimit = array_key_exists('row_limit', $definition) ? $definition['row_limit'] : min(1000, $configuredRowLimit);
        $rowLimit = min($configuredRowLimit, $requestedRowLimit);
        $project = (new AccessProjectService($this->db))->get($projectUuid);
        if (!is_array($project)) {
            return new WP_Error('smai_export_access_denied', 'Access project is unavailable.', ['status' => 403]);
        }
        $ttl = max(1, min(168, (int) get_option('smai_export_ttl_hours', 24)));
        $expiresTimestamp = min(time() + $ttl * HOUR_IN_SECONDS, (int) strtotime((string) $project['expires_at']));
        $normalized = [
            'source_type' => 'metric_snapshots',
            'metric_id' => $metricId,
            'metric_version' => $metricVersion,
            'columns' => $columns,
            'row_limit' => $rowLimit,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ];
        $uuid = Uuid::v4();
        $token = bin2hex(random_bytes(32));
        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_export_transaction_failed', 'Export request transaction could not start.', ['status' => 500]); }
        $ok = $wpdb->insert($this->db->table('exports'), [
            'export_uuid' => $uuid,
            'project_uuid' => $projectUuid,
            'requester_user_id' => $actorUserId,
            'state' => 'requested',
            'purpose' => $cleanPurpose,
            'definition_json' => Json::canonical($normalized),
            'columns_json' => Json::canonical($columns),
            'row_limit' => $rowLimit,
            'token_hash' => CryptoBox::tokenHash($token),
            'encryption_context' => $uuid,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresTimestamp),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $job = $ok === 1 ? (new JobQueue($this->db))->enqueue('export.build', [
            'export_uuid' => $uuid,
            'actor_user_id' => $actorUserId,
        ], 'export|' . $uuid) : new WP_Error('smai_export_store_failed', 'Export request could not be stored.', ['status' => 500]);
        if ($ok !== 1 || is_wp_error($job) || !$this->audit->logInOpenTransaction('analytics_export_requested', 'export', $uuid, 'success', [
            'metric_ref' => $metricId . '@' . $metricVersion,
            'column_count' => count($columns),
            'row_limit' => $rowLimit,
        ], $cleanPurpose, null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return is_wp_error($job) ? $job : new WP_Error('smai_export_store_failed', 'Export request and audit evidence could not be stored.', ['status' => 503]);
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_export_commit_failed', 'Export request could not be committed.', ['status' => 500]); }
        return [
            'export_uuid' => $uuid,
            'state' => 'requested',
            'download_token' => $token,
            'expires_at' => gmdate('c', $expiresTimestamp),
            'job' => $job,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        if (!$this->keyConfigured()) {
            throw new \RuntimeException('Secure export encryption is not configured.');
        }
        $uuid = (string) ($payload['export_uuid'] ?? '');
        if (!$this->validUuid($uuid)) {
            throw new \RuntimeException('Export identity is invalid.');
        }
        $table = $this->db->table('exports');
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE export_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($export) || (string) $export['state'] !== 'requested' || strtotime((string) $export['expires_at']) <= time()) {
            throw new \RuntimeException('Export is unavailable or expired.');
        }
        $definition = Json::object((string) $export['definition_json']);
        $columns = array_map('strval', Json::list((string) $export['columns_json']));
        $metricRef = 'metric:' . (string) ($definition['metric_id'] ?? '') . '@' . (string) ($definition['metric_version'] ?? '');
        if (!(new AccessProjectService($this->db))->authorize((string) $export['project_uuid'], (int) $export['requester_user_id'], $metricRef, $columns, (string) $export['purpose'])
            || (new MetricCatalog($this->db))->active((string) $definition['metric_id'], (string) $definition['metric_version']) === null) {
            throw new \RuntimeException('Export authorization is no longer valid.');
        }

        $wpdb = $this->db->wpdb();
        $claimed = $wpdb->update($table, ['state' => 'building', 'updated_at' => $this->db->now()], ['id' => (int) $export['id'], 'state' => 'requested']);
        if ($claimed !== 1) {
            throw new \RuntimeException('Export could not be claimed.');
        }
        try {
            $rows = $this->metricRows($definition, (int) $export['row_limit']);
            $csv = CsvSafe::render($rows, $columns);
            $sha = hash('sha256', $csv);
            $encrypted = (new CryptoBox(SMAI_EXPORT_KEY))->encrypt($csv, $uuid);
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Export build transaction could not start.'); }
            $payloadResult = $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$this->db->table('export_payloads')}` (export_uuid,encrypted_payload,payload_size,created_at) VALUES (%s,%s,%d,%s)
                 ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload),payload_size=VALUES(payload_size),created_at=VALUES(created_at)",
                $uuid, $encrypted, strlen($csv), $this->db->now()
            ));
            $ready = $wpdb->update($table, [
                'state' => 'ready',
                'file_path_hash' => hash('sha256', 'db-encrypted:' . $uuid),
                'file_sha256' => $sha,
                'completed_at' => $this->db->now(),
                'updated_at' => $this->db->now(),
            ], ['id' => (int) $export['id'], 'state' => 'building']);
            if ($payloadResult === false || $ready !== 1 || !$this->audit->logInOpenTransaction('analytics_export_ready', 'export', $uuid, 'success', [
                'rows' => count($rows), 'columns' => $columns, 'sha256' => $sha,
            ], (string) $export['purpose'], null, (int) $export['requester_user_id'])) {
                throw new \RuntimeException('Export payload or audit evidence could not be committed.');
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Export build could not be committed.'); }
            return ['export_uuid' => $uuid, 'state' => 'ready', 'rows' => count($rows), 'sha256' => $sha];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $wpdb->update($table, ['state' => 'failed', 'token_hash' => null, 'updated_at' => $this->db->now()], ['id' => (int) $export['id'], 'state' => 'building']);
            throw new \RuntimeException('Export build failed safely.');
        }
    }

    public function download(string $uuid, string $token, int $actorUserId): string|WP_Error
    {
        if (!$this->keyConfigured()) {
            return new WP_Error('smai_export_key_missing', 'Secure export encryption is not configured.', ['status' => 503]);
        }
        if (!$this->validUuid($uuid) || $actorUserId < 1 || preg_match('/^[0-9a-f]{64}$/i', $token) !== 1) {
            return new WP_Error('smai_export_unavailable', 'Export is unavailable.', ['status' => 404]);
        }
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('exports')}` WHERE export_uuid=%s AND state='ready'", $uuid
        ), ARRAY_A);
        if (!is_array($export) || (int) $export['requester_user_id'] !== $actorUserId
            || !is_string($export['token_hash']) || !hash_equals((string) $export['token_hash'], CryptoBox::tokenHash($token))
            || strtotime((string) $export['expires_at']) <= time() || $export['revoked_at'] !== null) {
            return new WP_Error('smai_export_unavailable', 'Export is unavailable.', ['status' => 404]);
        }
        $definition = Json::object((string) $export['definition_json']);
        $columns = array_map('strval', Json::list((string) $export['columns_json']));
        $metricRef = 'metric:' . (string) ($definition['metric_id'] ?? '') . '@' . (string) ($definition['metric_version'] ?? '');
        if (!(new AccessProjectService($this->db))->authorize((string) $export['project_uuid'], $actorUserId, $metricRef, $columns, (string) $export['purpose'])) {
            return new WP_Error('smai_export_unavailable', 'Export authorization is no longer active.', ['status' => 404]);
        }
        $encrypted = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
            "SELECT encrypted_payload FROM `{$this->db->table('export_payloads')}` WHERE export_uuid=%s", $uuid
        ));
        if (!is_string($encrypted)) {
            return new WP_Error('smai_export_payload_missing', 'Export payload is unavailable.', ['status' => 404]);
        }
        try {
            $csv = (new CryptoBox(SMAI_EXPORT_KEY))->decrypt($encrypted, $uuid);
        } catch (\Throwable $error) {
            return new WP_Error('smai_export_decryption_failed', 'Export payload could not be verified.', ['status' => 500]);
        }
        if (!is_string($export['file_sha256']) || !hash_equals((string) $export['file_sha256'], hash('sha256', $csv))
            || !$this->audit->log('analytics_export_downloaded', 'export', $uuid, 'success', [], (string) $export['purpose'], null, $actorUserId)) {
            return new WP_Error('smai_export_integrity_failed', 'Export integrity or access audit verification failed.', ['status' => 503]);
        }
        return $csv;
    }

    /** @param array<string,mixed> $definition @return array<int,array<string,mixed>> */
    private function metricRows(array $definition, int $limit): array
    {
        $metric = (new MetricCatalog($this->db))->active((string) $definition['metric_id'], (string) $definition['metric_version']);
        if ($metric === null) { return []; }
        $metricDefinition = (array) $metric['definition'];
        $baseMinimum = max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20));
        $table = $this->db->table('metric_snapshots');
        $where = ['s.metric_id=%s', 's.metric_version=%s', "s.state='published'", "s.quality_status NOT IN ('suppressed','invalidated')"];
        $args = [(string) $definition['metric_id'], (string) $definition['metric_version']];
        if (!empty($definition['window_start'])) { $where[] = 's.window_start>=%s'; $args[] = (string) $definition['window_start']; }
        if (!empty($definition['window_end'])) { $where[] = 's.window_end<=%s'; $args[] = (string) $definition['window_end']; }
        $scanLimit = max(1, min(10000, $limit * 5));
        $args[] = $scanLimit;
        $sql = "SELECT s.metric_id,s.metric_version,s.window_start,s.window_end,s.dimensions_json AS dimensions,s.value_decimal AS value,s.numerator_decimal AS numerator,s.denominator_decimal AS denominator,s.cohort_size,s.quality_status,s.data_through,s.caveats_json AS caveats FROM `{$table}` s INNER JOIN (SELECT metric_id,metric_version,window_start,window_end,dimensions_hash,MAX(snapshot_revision) AS revision FROM `{$table}` WHERE state='published' GROUP BY metric_id,metric_version,window_start,window_end,dimensions_hash) latest ON latest.metric_id=s.metric_id AND latest.metric_version=s.metric_version AND latest.window_start=s.window_start AND latest.window_end=s.window_end AND latest.dimensions_hash=s.dimensions_hash AND latest.revision=s.snapshot_revision WHERE " . implode(' AND ', $where) . ' ORDER BY s.window_end DESC LIMIT %d';
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare($sql, ...$args), ARRAY_A);
        if (!is_array($rows)) { return []; }
        $safe = [];
        foreach ($rows as $row) {
            $dimensions = Json::object((string) $row['dimensions']);
            if (PrivacyQueryPolicy::violations($metricDefinition, $dimensions) !== []) { continue; }
            $minimum = PrivacyQueryPolicy::effectiveMinimum($metricDefinition, $dimensions, $baseMinimum);
            if ((int) $row['cohort_size'] < $minimum) { continue; }
            $disclosure = SnapshotDisclosurePolicy::evaluate(
                (string) $row['quality_status'],
                $row['data_through'] ?? null,
                Json::list((string) $row['caveats']),
                $metricDefinition
            );
            $row['dimensions'] = $dimensions;
            $row['quality_status'] = $disclosure['quality_status'];
            $row['data_through'] = $disclosure['data_through'];
            $row['caveats'] = $disclosure['caveats'];
            $safe[] = $row;
            if (count($safe) >= $limit) { break; }
        }
        return $safe;
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || strlen($value) > 35 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) { return null; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function keyConfigured(): bool
    {
        return defined('SMAI_EXPORT_KEY') && is_string(SMAI_EXPORT_KEY) && strlen(SMAI_EXPORT_KEY) >= 32;
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
