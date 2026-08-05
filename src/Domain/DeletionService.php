<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class DeletionService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $scope */
    public function request(string $deletionKey, string $sourceModule, string $sourceVersion, array $scope, int $actorUserId): array|WP_Error
    {
        $normalized = $this->normalizeKey($deletionKey);
        if ($actorUserId < 1 || $normalized === null
            || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $sourceModule) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $sourceVersion) !== 1
            || (new SensitiveValueDetector())->violations($scope) !== []) {
            return new WP_Error('smai_invalid_deletion_request', 'Deletion request is invalid.', ['status' => 400]);
        }
        $scope = $this->normalizeScope($scope);
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $table = $this->db->table('deletion_jobs');
        $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (job_uuid,deletion_key,source_module,source_version,state,scope_json,retry_count,requested_at,updated_at) VALUES (%s,%s,%s,%s,'requested',%s,0,%s,%s)",
            $uuid, $normalized, $sourceModule, $sourceVersion, Json::canonical($scope), $now, $now
        ));
        if ($inserted === false) {
            return new WP_Error('smai_deletion_store_failed', 'Deletion request could not be stored.', ['status' => 500]);
        }
        if ($inserted === 0) {
            $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT job_uuid,state FROM `{$table}` WHERE deletion_key=%s AND source_module=%s AND source_version=%s", $normalized, $sourceModule, $sourceVersion), ARRAY_A);
            return ['job_uuid' => (string) ($existing['job_uuid'] ?? ''), 'state' => (string) ($existing['state'] ?? 'unknown'), 'duplicate' => true];
        }
        $job = (new JobQueue($this->db))->enqueue('deletion.apply', ['deletion_job_uuid' => $uuid, 'actor_user_id' => $actorUserId], 'deletion|' . $normalized . '|' . $sourceModule . '|' . $sourceVersion);
        if (is_wp_error($job)) {
            $this->db->wpdb()->update($table, ['state' => 'retrying', 'retry_count' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() + 300), 'updated_at' => $now], ['job_uuid' => $uuid]);
            return $job;
        }
        $this->audit->log('analytics_deletion_requested', 'deletion_job', $uuid, 'success', ['source_module' => $sourceModule, 'scope' => array_keys($scope)], 'privacy_rights', null, $actorUserId);
        return ['job_uuid' => $uuid, 'state' => 'requested', 'duplicate' => false, 'worker_job' => $job];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $uuid = (string) ($payload['deletion_job_uuid'] ?? '');
        $table = $this->db->table('deletion_jobs');
        $wpdb = $this->db->wpdb();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE job_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($job) || !in_array((string) $job['state'], ['requested','retrying','running'], true)) {
            throw new \RuntimeException('Deletion job is unavailable.');
        }
        if ((string) $job['state'] !== 'running') {
            $claimed = $wpdb->update($table, ['state' => 'running', 'next_retry_at' => null, 'updated_at' => $this->db->now()], ['id' => (int) $job['id'], 'state' => (string) $job['state']]);
            if ($claimed !== 1) {throw new \RuntimeException('Deletion job changed concurrently.');}
        }
        $key = (string) $job['deletion_key'];
        $results = [];
        $results['events'] = $this->deleteWhere('events', 'deletion_key', $key);
        $affectedBuilds = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT build_uuid FROM `{$this->db->table('dataset_rows')}` WHERE deletion_key=%s", $key));
        $results['dataset_rows'] = $this->deleteWhere('dataset_rows', 'deletion_key', $key);
        $results['experiment_facts'] = $this->deleteWhere('experiment_facts', 'subject_ref', $key);

        $invalidated = [];
        foreach (is_array($affectedBuilds) ? $affectedBuilds : [] as $buildUuid) {
            $snapshotRows = $wpdb->get_results($wpdb->prepare("SELECT id,metric_id,metric_version,window_start,window_end,dimensions_json FROM `{$this->db->table('metric_snapshots')}` WHERE build_uuid=%s AND state='published'", $buildUuid), ARRAY_A);
            foreach (is_array($snapshotRows) ? $snapshotRows : [] as $snapshot) {$invalidated[] = $snapshot;}
            $wpdb->query($wpdb->prepare("UPDATE `{$this->db->table('metric_snapshots')}` SET state='invalidated',quality_status='degraded',caveats_json=%s WHERE build_uuid=%s AND state='published'", Json::encode(['Source rows were removed by a privacy deletion; recomputation is required.']), $buildUuid));
        }
        $results['metric_snapshots_invalidated'] = count($invalidated);
        foreach ($invalidated as $snapshot) {
            do_action('smai_aggregate_recompute_required', [
                'metric_id' => $snapshot['metric_id'], 'metric_version' => $snapshot['metric_version'],
                'window_start' => $snapshot['window_start'], 'window_end' => $snapshot['window_end'],
                'dimensions' => Json::object((string) $snapshot['dimensions_json']), 'deletion_job_uuid' => $uuid,
            ]);
        }

        // Aggregate artifacts are conservatively revoked because any released aggregate may have incorporated the deleted subject.
        $now = $this->db->now();
        $exportIds = $wpdb->get_col("SELECT export_uuid FROM `{$this->db->table('exports')}` WHERE state IN ('requested','building','ready')");
        $results['exports_revoked'] = max(0, (int) $wpdb->query($wpdb->prepare("UPDATE `{$this->db->table('exports')}` SET state='revoked',token_hash=NULL,revoked_at=%s,updated_at=%s WHERE state IN ('requested','building','ready')", $now, $now)));
        foreach (is_array($exportIds) ? $exportIds : [] as $exportUuid) {
            $wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $exportUuid]);
        }
        $results['report_deliveries_revoked'] = max(0, (int) $wpdb->query($wpdb->prepare("UPDATE `{$this->db->table('report_deliveries')}` SET state='revoked',token_hash=NULL,bundle_json=NULL,revoked_at=%s,updated_at=%s WHERE state IN ('queued','ready','sent')", $now, $now)));

        $reconciliation = [];
        foreach (['events','dataset_rows','experiment_facts'] as $store) {
            $after = $this->eligibleCount($store, $key);
            $this->recordReconciliation((int) $job['id'], $store, (int) $results[$store], $after, $after === 0 ? 'verified' : 'failed');
            $reconciliation[$store] = ['deleted_or_invalidated' => (int) $results[$store], 'eligible_after' => $after, 'state' => $after === 0 ? 'verified' : 'failed'];
        }
        foreach (['metric_snapshots_invalidated','exports_revoked','report_deliveries_revoked'] as $store) {
            $this->recordReconciliation((int) $job['id'], $store, (int) $results[$store], 0, 'verified');
            $reconciliation[$store] = ['deleted_or_invalidated' => (int) $results[$store], 'eligible_after' => 0, 'state' => 'verified'];
        }

        foreach ($this->activeProviders() as $provider) {
            $store = 'provider:' . $provider['provider_id'] . '@' . $provider['provider_version'];
            $evidence = apply_filters('smai_delete_from_provider', null, $provider['provider_id'], $provider['provider_version'], $key, Json::object((string) $job['scope_json']), $uuid);
            $verified = is_array($evidence) && ($evidence['verified'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/', (string) ($evidence['evidence_hash'] ?? '')) === 1;
            if (in_array((string) $provider['provider_id'], ['local','local-wordpress'], true)) {
                $verified = true;
                $evidence = ['verified' => true, 'evidence_hash' => hash('sha256', 'local|' . $uuid . '|' . $key)];
            }
            $this->recordReconciliation((int) $job['id'], $store, 0, $verified ? 0 : 1, $verified ? 'verified' : 'failed', (string) ($evidence['evidence_hash'] ?? hash('sha256', $store . '|failed')));
            $reconciliation[$store] = ['eligible_after' => $verified ? 0 : 1, 'state' => $verified ? 'verified' : 'failed'];
        }

        $failed = array_filter($reconciliation, static fn(array $item): bool => $item['state'] !== 'verified');
        if ($failed === []) {
            $wpdb->update($table, ['state' => 'completed', 'result_json' => Json::encode($reconciliation), 'completed_at' => $now, 'next_retry_at' => null, 'updated_at' => $now], ['id' => (int) $job['id']]);
            $this->audit->log('analytics_deletion_completed', 'deletion_job', $uuid, 'success', ['stores' => array_keys($reconciliation)], 'privacy_rights', null, (int) ($payload['actor_user_id'] ?? 0));
            do_action('smai_analytics_deletion_completed', ['job_uuid' => $uuid, 'source_module' => $job['source_module']]);
            return ['job_uuid' => $uuid, 'state' => 'completed', 'reconciliation' => $reconciliation];
        }

        $retry = (int) $job['retry_count'] + 1;
        $delay = min(DAY_IN_SECONDS, 300 * (2 ** min(7, $retry - 1)));
        $wpdb->update($table, ['state' => 'retrying', 'result_json' => Json::encode($reconciliation), 'retry_count' => $retry, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'updated_at' => $now], ['id' => (int) $job['id']]);
        $this->audit->log('analytics_deletion_retrying', 'deletion_job', $uuid, 'failed', ['failed_stores' => array_keys($failed), 'retry_count' => $retry], 'privacy_rights', null, (int) ($payload['actor_user_id'] ?? 0));
        throw new \RuntimeException('Deletion reconciliation is incomplete and has been scheduled for retry.');
    }

    /** @return array<int,array<string,mixed>> */
    private function activeProviders(): array
    {
        $rows = $this->db->wpdb()->get_results("SELECT provider_id,provider_version FROM `{$this->db->table('providers')}` WHERE state IN ('active','draining','purge_pending')", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function normalizeScope(array $scope): array
    {
        $allowed = ['events','datasets','experiments','exports','reports','providers','reason'];
        $out = [];
        foreach ($scope as $key => $value) {
            if (!in_array((string) $key, $allowed, true)) {continue;}
            $out[(string) $key] = is_bool($value) || is_int($value) || is_string($value) ? $value : null;
        }
        return $out;
    }

    private function normalizeKey(string $key): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) {return strtolower($key);}
        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32 || $key === '') {return null;}
        return hash_hmac('sha256', 'deletion|' . $key, SMAI_PSEUDONYM_KEY);
    }

    private function deleteWhere(string $tableName, string $column, string $key): int
    {
        $allowedColumns = ['events' => ['deletion_key'], 'dataset_rows' => ['deletion_key'], 'experiment_facts' => ['subject_ref']];
        if (!in_array($column, $allowedColumns[$tableName] ?? [], true)) {throw new \InvalidArgumentException('Invalid deletion target.');}
        return max(0, (int) $this->db->wpdb()->query($this->db->wpdb()->prepare("DELETE FROM `{$this->db->table($tableName)}` WHERE `{$column}`=%s", $key)));
    }

    private function eligibleCount(string $store, string $key): int
    {
        return match ($store) {
            'events' => (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('events')}` WHERE deletion_key=%s", $key)),
            'dataset_rows' => (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('dataset_rows')}` WHERE deletion_key=%s", $key)),
            'experiment_facts' => (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('experiment_facts')}` WHERE subject_ref=%s", $key)),
            default => 0,
        };
    }

    private function recordReconciliation(int $jobId, string $store, int $before, int $after, string $state, ?string $evidenceHash = null): void
    {
        $hash = $evidenceHash ?? hash('sha256', Json::canonical(['store' => $store, 'before' => $before, 'after' => $after, 'state' => $state]));
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT INTO `{$this->db->table('deletion_reconciliations')}` (deletion_job_id,store_name,eligible_before,eligible_after,state,evidence_hash,created_at) VALUES (%d,%s,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE eligible_before=VALUES(eligible_before),eligible_after=VALUES(eligible_after),state=VALUES(state),evidence_hash=VALUES(evidence_hash),created_at=VALUES(created_at)",
            $jobId, $store, $before, $after, $state, $hash, $this->db->now()
        ));
    }
}
