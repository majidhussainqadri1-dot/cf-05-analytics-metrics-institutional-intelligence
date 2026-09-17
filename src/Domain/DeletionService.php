<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
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
        $scope = $this->normalizeScope($scope);
        if ($actorUserId < 1 || $normalized === null || $scope === []
            || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $sourceModule) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $sourceVersion) !== 1
            || (new SensitiveValueDetector())->violations($scope) !== []) {
            return new WP_Error('smai_invalid_deletion_request', 'Deletion request is invalid.', ['status' => 400]);
        }
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $table = $this->db->table('deletion_jobs');
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_deletion_transaction_failed', 'Deletion request transaction could not start.', ['status'=>500]); }
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (job_uuid,deletion_key,source_module,source_version,state,scope_json,retry_count,requested_at,updated_at) VALUES (%s,%s,%s,%s,'requested',%s,0,%s,%s)",
            $uuid, $normalized, $sourceModule, $sourceVersion, Json::canonical($scope), $now, $now
        ));
        if ($inserted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_deletion_store_failed', 'Deletion request could not be stored.', ['status' => 500]);
        }
        if ($inserted === 0) {
            $wpdb->query('ROLLBACK');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT job_uuid,state,scope_json FROM `{$table}` WHERE deletion_key=%s AND source_module=%s AND source_version=%s", $normalized, $sourceModule, $sourceVersion), ARRAY_A);
            if (!is_array($existing) || !hash_equals(Json::canonical($scope), (string) $existing['scope_json'])) {
                return new WP_Error('smai_deletion_idempotency_conflict', 'Deletion identity was reused with a different scope.', ['status' => 409]);
            }
            return ['job_uuid' => (string) $existing['job_uuid'], 'state' => (string) $existing['state'], 'duplicate' => true];
        }
        $worker = (new JobQueue($this->db))->enqueue('deletion.apply', ['deletion_job_uuid' => $uuid, 'actor_user_id' => $actorUserId], 'deletion|' . $normalized . '|' . $sourceModule . '|' . $sourceVersion);
        if (is_wp_error($worker) || !$this->audit->logInOpenTransaction('analytics_deletion_requested', 'deletion_job', $uuid, 'success', [
            'source_module' => $sourceModule, 'scope' => array_keys(array_filter($scope, static fn(mixed $v): bool => $v === true)),
        ], 'privacy_rights', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return is_wp_error($worker) ? $worker : new WP_Error('smai_audit_failed', 'Deletion request audit evidence failed.', ['status' => 503]);
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_deletion_commit_failed','Deletion request could not be committed.',['status'=>500]); }
        return ['job_uuid' => $uuid, 'state' => 'requested', 'duplicate' => false, 'worker_job' => $worker];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $uuid = (string) ($payload['deletion_job_uuid'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1) { throw new \RuntimeException('Deletion identity is invalid.'); }
        $table = $this->db->table('deletion_jobs');
        $wpdb = $this->db->wpdb();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE job_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($job) || !in_array((string) $job['state'], ['requested','retrying','running'], true)) {
            throw new \RuntimeException('Deletion job is unavailable.');
        }
        if ((string) $job['state'] !== 'running' && $wpdb->update($table, ['state' => 'running', 'next_retry_at' => null, 'updated_at' => $this->db->now()], ['id' => (int) $job['id'], 'state' => (string) $job['state']]) !== 1) {
            throw new \RuntimeException('Deletion job changed concurrently.');
        }
        $key = (string) $job['deletion_key'];
        $scope = Json::object((string) $job['scope_json']);
        $results = [];
        $reconciliation = [];
        $invalidatedMetrics = [];
        $now = $this->db->now();

        try {
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Deletion local transaction could not start.'); }
            if (($scope['events'] ?? false) === true) {
                $before = $this->eligibleCount('events', $key);
                $results['events'] = $this->deleteWhere('events', 'deletion_key', $key);
                $this->reconcileLocal((int) $job['id'], 'events', $before, $this->eligibleCount('events', $key), $reconciliation);
            }
            $affectedBuilds = [];
            if (($scope['datasets'] ?? false) === true) {
                $affectedBuilds = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT build_uuid FROM `{$this->db->table('dataset_rows')}` WHERE deletion_key=%s", $key));
                $before = $this->eligibleCount('dataset_rows', $key);
                $results['dataset_rows'] = $this->deleteWhere('dataset_rows', 'deletion_key', $key);
                $this->reconcileLocal((int) $job['id'], 'dataset_rows', $before, $this->eligibleCount('dataset_rows', $key), $reconciliation);
            }
            if (($scope['experiments'] ?? false) === true) {
                $before = $this->eligibleCount('experiment_facts', $key);
                $results['experiment_facts'] = $this->deleteWhere('experiment_facts', 'deletion_key', $key);
                $this->reconcileLocal((int) $job['id'], 'experiment_facts', $before, $this->eligibleCount('experiment_facts', $key), $reconciliation);
            }
            foreach (is_array($affectedBuilds) ? $affectedBuilds : [] as $buildUuid) {
                $snapshots = $wpdb->get_results($wpdb->prepare("SELECT metric_id,metric_version FROM `{$this->db->table('metric_snapshots')}` WHERE build_uuid=%s AND state='published'", $buildUuid), ARRAY_A);
                foreach (is_array($snapshots) ? $snapshots : [] as $snapshot) {
                    $invalidatedMetrics[(string) $snapshot['metric_id'] . '@' . (string) $snapshot['metric_version']] = true;
                }
                if ($wpdb->query($wpdb->prepare("UPDATE `{$this->db->table('metric_snapshots')}` SET state='invalidated',quality_status='invalidated',caveats_json=%s WHERE build_uuid=%s AND state='published'", Json::encode(['Source rows were removed by a privacy deletion; recomputation is required.']), $buildUuid)) === false) {
                    throw new \RuntimeException('Snapshot invalidation failed.');
                }
            }
            $results['metric_snapshots_invalidated'] = count($invalidatedMetrics);
            if (($scope['exports'] ?? false) === true) {
                $results['exports_revoked'] = $this->revokeExports(array_keys($invalidatedMetrics), $now);
            }
            if (($scope['reports'] ?? false) === true) {
                $results['reports_revoked'] = $this->revokeReports(array_keys($invalidatedMetrics), $now);
            }
            foreach (['metric_snapshots_invalidated','exports_revoked','reports_revoked'] as $store) {
                if (isset($results[$store])) {
                    $this->recordReconciliation((int) $job['id'], $store, (int) $results[$store], 0, 'verified');
                    $reconciliation[$store] = ['eligible_before' => (int) $results[$store], 'eligible_after' => 0, 'state' => 'verified'];
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Deletion local transaction could not be committed.'); }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->retry($job, $reconciliation, ['local_transaction']);
            throw new \RuntimeException('Local deletion failed safely.');
        }

        if (($scope['providers'] ?? false) === true) {
            foreach ($this->activeProviders() as $provider) {
                $store = 'provider:' . $provider['provider_id'] . '@' . $provider['provider_version'];
                $evidence = apply_filters('smai_delete_from_provider', null, $provider['provider_id'], $provider['provider_version'], $key, $scope, $uuid);
                $verified = is_array($evidence) && ($evidence['verified'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/', (string) ($evidence['evidence_hash'] ?? '')) === 1;
                if (in_array((string) $provider['provider_id'], ['local','local-wordpress'], true)) {
                    $verified = true; $evidence = ['evidence_hash' => hash('sha256', 'local|' . $uuid . '|' . $key)];
                }
                $this->recordReconciliation((int) $job['id'], $store, 0, $verified ? 0 : 1, $verified ? 'verified' : 'failed', (string) ($evidence['evidence_hash'] ?? hash('sha256', $store . '|failed')));
                $reconciliation[$store] = ['eligible_before' => 0, 'eligible_after' => $verified ? 0 : 1, 'state' => $verified ? 'verified' : 'failed'];
            }
        }

        $failed = array_filter($reconciliation, static fn(array $item): bool => ($item['state'] ?? '') !== 'verified');
        if ($failed !== []) {
            $this->retry($job, $reconciliation, array_keys($failed));
            throw new \RuntimeException('Deletion reconciliation is incomplete and has been scheduled for retry.');
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            $this->retry($job, $reconciliation, ['completion_transaction']);
            throw new \RuntimeException('Deletion completion transaction could not start.');
        }
        $completed = $wpdb->update($table, ['state' => 'completed', 'result_json' => Json::encode($reconciliation), 'completed_at' => $now, 'next_retry_at' => null, 'updated_at' => $now], ['id' => (int) $job['id'], 'state' => 'running']);
        $audited = $completed === 1 && $this->audit->logInOpenTransaction('analytics_deletion_completed', 'deletion_job', $uuid, 'success', ['stores' => array_keys($reconciliation)], 'privacy_rights', null, (int) ($payload['actor_user_id'] ?? 0));
        if (!$audited || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            $this->retry($job, $reconciliation, ['completion_evidence']);
            throw new \RuntimeException('Deletion completion evidence could not be committed.');
        }
        foreach (array_keys($invalidatedMetrics) as $metricRef) { do_action('smai_aggregate_recompute_required', ['metric_ref' => $metricRef, 'deletion_job_uuid' => $uuid]); }
        do_action('smai_analytics_deletion_completed', ['job_uuid' => $uuid, 'source_module' => $job['source_module']]);
        return ['job_uuid' => $uuid, 'state' => 'completed', 'reconciliation' => $reconciliation];
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
        if (array_diff(array_keys($scope), ['events','datasets','experiments','exports','reports','providers','reason']) !== []) { return []; }
        $out = [];
        foreach (['events','datasets','experiments','exports','reports','providers'] as $key) {
            if (isset($scope[$key]) && !is_bool($scope[$key])) { return []; }
            $out[$key] = (bool) ($scope[$key] ?? true);
        }
        if (!array_filter($out, static fn(bool $v): bool => $v)) { return []; }
        $reason = Text::truncate(trim(wp_strip_all_tags((string) ($scope['reason'] ?? 'privacy deletion'))), 500);
        if (strlen($reason) < 8) { return []; }
        $out['reason'] = $reason;
        return $out;
    }

    private function normalizeKey(string $key): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) { return strtolower($key); }
        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32 || $key === '' || strlen($key) > 512) { return null; }
        return hash_hmac('sha256', 'deletion|' . $key, SMAI_PSEUDONYM_KEY);
    }

    private function deleteWhere(string $tableName, string $column, string $key): int
    {
        $allowed = ['events' => ['deletion_key'], 'dataset_rows' => ['deletion_key'], 'experiment_facts' => ['deletion_key']];
        if (!in_array($column, $allowed[$tableName] ?? [], true)) { throw new \InvalidArgumentException('Invalid deletion target.'); }
        $result = $this->db->wpdb()->query($this->db->wpdb()->prepare("DELETE FROM `{$this->db->table($tableName)}` WHERE `{$column}`=%s", $key));
        if ($result === false) { throw new \RuntimeException('Deletion query failed.'); }
        return (int) $result;
    }

    private function eligibleCount(string $store, string $key): int
    {
        $column = match ($store) { 'events','dataset_rows','experiment_facts' => 'deletion_key', default => null };
        if ($column === null) { return 0; }
        return (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table($store)}` WHERE `{$column}`=%s", $key));
    }

    /** @param array<string,array<string,mixed>> $reconciliation */
    private function reconcileLocal(int $jobId, string $store, int $before, int $after, array &$reconciliation): void
    {
        $state = $after === 0 ? 'verified' : 'failed';
        $this->recordReconciliation($jobId, $store, $before, $after, $state);
        $reconciliation[$store] = ['eligible_before' => $before, 'eligible_after' => $after, 'state' => $state];
    }

    /** @param array<int,string> $metricRefs */
    private function revokeExports(array $metricRefs, string $now): int
    {
        if ($metricRefs === []) { return 0; }
        $rows = $this->db->wpdb()->get_results("SELECT id,export_uuid,definition_json FROM `{$this->db->table('exports')}` WHERE state IN ('requested','building','ready') ORDER BY id LIMIT 10000", ARRAY_A);
        $count = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $definition = Json::object((string) $row['definition_json']);
            $ref = (string) ($definition['metric_id'] ?? '') . '@' . (string) ($definition['metric_version'] ?? '');
            if (!in_array($ref, $metricRefs, true)) { continue; }
            if ($this->db->wpdb()->update($this->db->table('exports'), ['state' => 'revoked','token_hash' => null,'revoked_at' => $now,'expires_at' => $now,'updated_at' => $now], ['id' => (int) $row['id']]) === 1) {
                $this->db->wpdb()->delete($this->db->table('export_payloads'), ['export_uuid' => $row['export_uuid']]); $count++;
            }
        }
        return $count;
    }

    /** @param array<int,string> $metricRefs */
    private function revokeReports(array $metricRefs, string $now): int
    {
        if ($metricRefs === []) { return 0; }
        $rows = $this->db->wpdb()->get_results("SELECT id,report_uuid,definition_json FROM `{$this->db->table('reports')}` WHERE state IN ('draft','active','paused') ORDER BY id LIMIT 10000", ARRAY_A);
        $count = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $affected = false;
            foreach ((array) (Json::object((string) $row['definition_json'])['metrics'] ?? []) as $metric) {
                if (is_array($metric) && in_array((string) ($metric['metric_id'] ?? '') . '@' . (string) ($metric['metric_version'] ?? ''), $metricRefs, true)) { $affected = true; break; }
            }
            if (!$affected) { continue; }
            if ($this->db->wpdb()->update($this->db->table('reports'), ['state' => 'revoked','next_run_at' => null,'updated_at' => $now], ['id' => (int) $row['id']]) === 1) {
                $this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('report_deliveries')}` SET state='revoked',token_hash=NULL,bundle_json=NULL,revoked_at=%s,expires_at=%s,updated_at=%s WHERE report_uuid=%s AND state IN ('queued','ready','sent')", $now,$now,$now,$row['report_uuid']));
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string,array<string,mixed>> $reconciliation @param array<int,string> $failedStores */
    private function retry(array $job, array $reconciliation, array $failedStores): void
    {
        $retry = (int) $job['retry_count'] + 1;
        $delay = min(DAY_IN_SECONDS, 300 * (2 ** min(7, max(0, $retry - 1))));
        $this->db->wpdb()->update($this->db->table('deletion_jobs'), [
            'state' => 'retrying', 'result_json' => Json::encode($reconciliation), 'retry_count' => $retry,
            'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'updated_at' => $this->db->now(),
        ], ['id' => (int) $job['id']]);
        $this->audit->log('analytics_deletion_retrying', 'deletion_job', (string) $job['job_uuid'], 'failed', ['failed_stores' => $failedStores, 'retry_count' => $retry], 'privacy_rights', null, null, 'system');
    }

    private function recordReconciliation(int $jobId, string $store, int $before, int $after, string $state, ?string $evidenceHash = null): void
    {
        $hash = $evidenceHash ?? hash('sha256', Json::canonical(['store' => $store, 'before' => $before, 'after' => $after, 'state' => $state]));
        $result = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT INTO `{$this->db->table('deletion_reconciliations')}` (deletion_job_id,store_name,eligible_before,eligible_after,state,evidence_hash,created_at) VALUES (%d,%s,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE eligible_before=VALUES(eligible_before),eligible_after=VALUES(eligible_after),state=VALUES(state),evidence_hash=VALUES(evidence_hash),created_at=VALUES(created_at)",
            $jobId, $store, $before, $after, $state, $hash, $this->db->now()
        ));
        if ($result === false) { throw new \RuntimeException('Deletion reconciliation evidence failed.'); }
    }
}
