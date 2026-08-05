<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\CryptoBox;
use Sabri\AnalyticsIntelligence\Infrastructure\CsvSafe;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class ExportService
{
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
        if (!defined('SMAI_EXPORT_KEY') || !is_string(SMAI_EXPORT_KEY) || strlen(SMAI_EXPORT_KEY) < 32) {
            return new WP_Error('smai_export_key_missing', 'Secure export encryption is not configured.', ['status' => 503]);
        }
        if (strlen(trim($purpose)) < 8) {
            return new WP_Error('smai_export_purpose_required', 'A meaningful project purpose is required.', ['status' => 400]);
        }
        $source = (string) ($definition['source_type'] ?? '');
        if ($source !== 'metric_snapshots') {
            return new WP_Error('smai_export_source_denied', 'Only approved aggregate metric snapshots can be exported.', ['status' => 403]);
        }
        $metricId = (string) ($definition['metric_id'] ?? '');
        $metricVersion = (string) ($definition['metric_version'] ?? '');
        $columns = is_array($definition['columns'] ?? null) ? array_values(array_unique(array_map('strval', $definition['columns']))) : [];
        $allowedColumns = ['metric_id','metric_version','window_start','window_end','dimensions_json','value_decimal','numerator_decimal','denominator_decimal','cohort_size','quality_status','data_through','caveats_json'];
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $metricVersion) !== 1
            || $columns === [] || array_diff($columns, $allowedColumns) !== []) {
            return new WP_Error('smai_invalid_export_definition', 'Export definition is invalid.', ['status' => 400]);
        }
        $datasetRef = 'metric:' . $metricId . '@' . $metricVersion;
        if (!(new AccessProjectService($this->db))->authorize($projectUuid, $actorUserId, $datasetRef, $columns, $purpose)) {
            return new WP_Error('smai_export_access_denied', 'Access project does not authorize this export.', ['status' => 403]);
        }
        $rowLimit = max(1, min((int) get_option('smai_max_export_rows', 10000), (int) ($definition['row_limit'] ?? 1000)));
        $ttl = max(1, min(168, (int) get_option('smai_export_ttl_hours', 24)));
        $uuid = Uuid::v4();
        $token = bin2hex(random_bytes(32));
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('exports'), [
            'export_uuid' => $uuid,
            'project_uuid' => $projectUuid,
            'requester_user_id' => $actorUserId,
            'state' => 'requested',
            'purpose' => Text::truncate(trim(wp_strip_all_tags($purpose)), 190),
            'definition_json' => Json::canonical($definition),
            'columns_json' => Json::canonical($columns),
            'row_limit' => $rowLimit,
            'token_hash' => CryptoBox::tokenHash($token),
            'encryption_context' => $uuid,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl * HOUR_IN_SECONDS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_export_store_failed', 'Export request could not be stored.', ['status' => 500]);
        }
        $job = (new JobQueue($this->db))->enqueue('export.build', [
            'export_uuid' => $uuid,
            'actor_user_id' => $actorUserId,
        ], 'export|' . $uuid);
        return is_wp_error($job) ? $job : [
            'export_uuid' => $uuid,
            'state' => 'requested',
            'download_token' => $token,
            'expires_at' => gmdate('c', time() + $ttl * HOUR_IN_SECONDS),
            'job' => $job,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $uuid = (string) ($payload['export_uuid'] ?? '');
        $table = $this->db->table('exports');
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE export_uuid=%s",
            $uuid
        ), ARRAY_A);
        if (!is_array($export) || (string) $export['state'] !== 'requested' || strtotime((string) $export['expires_at']) <= time()) {
            throw new \RuntimeException('Export is unavailable or expired.');
        }
        $this->db->wpdb()->update($table, ['state' => 'building', 'updated_at' => $this->db->now()], ['id' => (int) $export['id'], 'state' => 'requested']);

        $definition = Json::object((string) $export['definition_json']);
        $columns = array_map('strval', Json::list((string) $export['columns_json']));
        $rows = $this->metricRows($definition, (int) $export['row_limit']);
        $csv = CsvSafe::render($rows, $columns);
        $sha = hash('sha256', $csv);
        $box = new CryptoBox(SMAI_EXPORT_KEY);
        $encrypted = $box->encrypt($csv, $uuid);
        $payloadTable = $this->db->table('export_payloads');
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT INTO `{$payloadTable}` (export_uuid,encrypted_payload,payload_size,created_at) VALUES (%s,%s,%d,%s)
             ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload),payload_size=VALUES(payload_size),created_at=VALUES(created_at)",
            $uuid,
            $encrypted,
            strlen($csv),
            $this->db->now()
        ));
        $this->db->wpdb()->update($table, [
            'state' => 'ready',
            'file_path_hash' => hash('sha256', 'db-encrypted:' . $uuid),
            'file_sha256' => $sha,
            'completed_at' => $this->db->now(),
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $export['id']]);
        $this->audit->log('analytics_export_ready', 'export', $uuid, 'success', [
            'rows' => count($rows),
            'columns' => $columns,
            'sha256' => $sha,
        ], (string) $export['purpose'], null, (int) $export['requester_user_id']);
        return ['export_uuid' => $uuid, 'state' => 'ready', 'rows' => count($rows), 'sha256' => $sha];
    }

    public function download(string $uuid, string $token, int $actorUserId): string|WP_Error
    {
        if (!defined('SMAI_EXPORT_KEY') || !is_string(SMAI_EXPORT_KEY) || strlen(SMAI_EXPORT_KEY) < 32) {
            return new WP_Error('smai_export_key_missing', 'Secure export encryption is not configured.', ['status' => 503]);
        }
        $table = $this->db->table('exports');
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE export_uuid=%s AND state='ready'",
            $uuid
        ), ARRAY_A);
        if (!is_array($export)
            || (int) $export['requester_user_id'] !== $actorUserId
            || !hash_equals((string) $export['token_hash'], CryptoBox::tokenHash($token))
            || strtotime((string) $export['expires_at']) <= time()
            || $export['revoked_at'] !== null) {
            return new WP_Error('smai_export_unavailable', 'Export is unavailable.', ['status' => 404]);
        }
        $encrypted = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
            "SELECT encrypted_payload FROM `{$this->db->table('export_payloads')}` WHERE export_uuid=%s",
            $uuid
        ));
        if (!is_string($encrypted)) {
            return new WP_Error('smai_export_payload_missing', 'Export payload is unavailable.', ['status' => 404]);
        }
        try {
            $csv = (new CryptoBox(SMAI_EXPORT_KEY))->decrypt($encrypted, $uuid);
        } catch (\Throwable $error) {
            return new WP_Error('smai_export_decryption_failed', 'Export payload could not be verified.', ['status' => 500]);
        }
        if (!hash_equals((string) $export['file_sha256'], hash('sha256', $csv))) {
            return new WP_Error('smai_export_integrity_failed', 'Export integrity verification failed.', ['status' => 500]);
        }
        $this->audit->log('analytics_export_downloaded', 'export', $uuid, 'success', [], (string) $export['purpose'], null, $actorUserId);
        return $csv;
    }

    /** @param array<string,mixed> $definition @return array<int,array<string,mixed>> */
    private function metricRows(array $definition, int $limit): array
    {
        $table = $this->db->table('metric_snapshots');
        $where = ['metric_id=%s', 'metric_version=%s', "state='published'", "quality_status<>'suppressed'"];
        $args = [(string) $definition['metric_id'], (string) $definition['metric_version']];
        if (!empty($definition['window_start'])) {
            $where[] = 'window_start>=%s';
            $args[] = gmdate('Y-m-d H:i:s', (int) strtotime((string) $definition['window_start']));
        }
        if (!empty($definition['window_end'])) {
            $where[] = 'window_end<=%s';
            $args[] = gmdate('Y-m-d H:i:s', (int) strtotime((string) $definition['window_end']));
        }
        $args[] = $limit;
        $sql = "SELECT s.metric_id,s.metric_version,s.window_start,s.window_end,s.dimensions_json,s.value_decimal,s.numerator_decimal,s.denominator_decimal,s.cohort_size,s.quality_status,s.data_through,s.caveats_json FROM `{$table}` s INNER JOIN (SELECT metric_id,metric_version,window_start,window_end,dimensions_hash,MAX(snapshot_revision) AS revision FROM `{$table}` WHERE state='published' GROUP BY metric_id,metric_version,window_start,window_end,dimensions_hash) latest ON latest.metric_id=s.metric_id AND latest.metric_version=s.metric_version AND latest.window_start=s.window_start AND latest.window_end=s.window_end AND latest.dimensions_hash=s.dimensions_hash AND latest.revision=s.snapshot_revision WHERE " . implode(' AND ', array_map(static fn(string $clause): string => 's.' . $clause, $where)) . ' ORDER BY s.window_end DESC LIMIT %d';
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare($sql, ...$args), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
