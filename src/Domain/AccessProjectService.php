<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class AccessProjectService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<int,string> $datasets @param array<string,array<int,string>> $fields */
    public function request(string $name, string $purpose, array $datasets, array $fields, string $expiresAt, bool $trainingConfirmed, int $actorUserId): array|WP_Error
    {
        $expiry = $this->strictTimestamp($expiresAt);
        $cleanName = Text::truncate(trim(wp_strip_all_tags($name)), 190);
        $cleanPurpose = Text::truncate(trim(wp_strip_all_tags($purpose)), 2000);
        $detector = new SensitiveValueDetector();
        if ($actorUserId < 1 || strlen($cleanName) < 3 || strlen($cleanPurpose) < 12
            || $detector->violations([$cleanName, $cleanPurpose]) !== []
            || $expiry === false || $expiry <= time() || $expiry > time() + 366 * DAY_IN_SECONDS
            || $datasets === [] || count($datasets) > 50 || count($fields) > 50) {
            return new WP_Error('smai_invalid_access_request', 'Analytics access request is invalid.', ['status' => 400]);
        }

        $cleanDatasets = [];
        foreach ($datasets as $dataset) {
            if (!is_string($dataset) || preg_match('/^(?:metric:)?[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/', $dataset) !== 1) {
                return new WP_Error('smai_invalid_dataset_ref', 'Dataset reference is invalid.', ['status' => 400]);
            }
            $cleanDatasets[$dataset] = true;
        }
        $cleanDatasetList = array_keys($cleanDatasets);
        sort($cleanDatasetList, SORT_STRING);

        $cleanFields = [];
        foreach ($fields as $dataset => $list) {
            if (!is_string($dataset) || !isset($cleanDatasets[$dataset]) || !is_array($list) || count($list) > 100) {
                return new WP_Error('smai_invalid_project_fields', 'Project fields are invalid.', ['status' => 400]);
            }
            $unique = [];
            foreach ($list as $field) {
                if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
                    return new WP_Error('smai_invalid_project_field', 'Project field is invalid.', ['status' => 400]);
                }
                $unique[$field] = true;
            }
            $requested = array_keys($unique);
            sort($requested, SORT_STRING);
            if (!$this->fieldsExist($dataset, $requested)) {
                return new WP_Error('smai_project_field_not_available', 'A requested field is not available from the approved dataset contract.', ['status' => 409]);
            }
            $cleanFields[$dataset] = $requested;
        }
        foreach ($cleanDatasetList as $datasetRef) {
            if (!$this->referenceExists($datasetRef)) {
                return new WP_Error('smai_project_dataset_unavailable', 'An access-project dataset is not published and available.', ['status' => 409]);
            }
            $cleanFields[$datasetRef] ??= [];
        }
        ksort($cleanFields);

        $uuid = Uuid::v4();
        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_access_transaction_failed', 'Access request transaction could not start.', ['status' => 500]);
        }
        $ok = $wpdb->insert($this->db->table('access_projects'), [
            'project_uuid' => $uuid,
            'name' => $cleanName,
            'purpose' => $cleanPurpose,
            'state' => 'requested',
            'owner_user_id' => $actorUserId,
            'datasets_json' => Json::canonical($cleanDatasetList),
            'fields_json' => Json::canonical($cleanFields),
            'training_confirmed' => $trainingConfirmed ? 1 : 0,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry),
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1 || !$this->audit->logInOpenTransaction('analytics_access_requested', 'access_project', $uuid, 'success', [
            'dataset_count' => count($cleanDatasetList),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry),
            'training_confirmed' => $trainingConfirmed,
        ], 'access_governance', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_project_store_failed', 'Access project and its audit evidence could not be stored.', ['status' => 503]);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_commit_failed', 'Access project could not be committed.', ['status' => 500]);
        }
        return ['project_uuid' => $uuid, 'state' => 'requested', 'row_version' => 1];
    }

    public function approve(string $uuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        if (!$this->validUuid($uuid) || $expectedVersion < 1 || $actorUserId < 1) {
            return new WP_Error('smai_invalid_access_request', 'Access approval request is invalid.', ['status' => 400]);
        }
        $project = $this->get($uuid);
        if ($project === null || (string) $project['state'] !== 'requested' || (int) $project['row_version'] !== $expectedVersion
            || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_access_project_stale', 'Access project is unavailable, expired or stale.', ['status' => 409]);
        }
        if ((int) $project['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Project owner cannot approve their own access.', ['status' => 403]);
        }
        if ((int) $project['training_confirmed'] !== 1) {
            return new WP_Error('smai_training_required', 'Required privacy training is not confirmed.', ['status' => 409]);
        }
        foreach (array_map('strval', Json::list((string) $project['datasets_json'])) as $reference) {
            if (!$this->referenceExists($reference)) {
                return new WP_Error('smai_project_dataset_unavailable', 'An approved project source is no longer available.', ['status' => 409]);
            }
        }

        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_access_transaction_failed', 'Access approval transaction could not start.', ['status' => 500]);
        }
        $now = $this->db->now();
        $updated = $wpdb->update($this->db->table('access_projects'), [
            'state' => 'active',
            'approved_by' => $actorUserId,
            'approved_at' => $now,
            'reviewed_at' => $now,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $now,
        ], ['id' => (int) $project['id'], 'state' => 'requested', 'row_version' => $expectedVersion]);
        if ($updated !== 1 || !$this->audit->logInOpenTransaction('analytics_access_granted', 'access_project', $uuid, 'success', [
            'owner_user_id' => (int) $project['owner_user_id'],
            'expires_at' => $project['expires_at'],
        ], 'access_governance', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_project_conflict', 'Access approval or its audit evidence could not be committed.', ['status' => 409]);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_commit_failed', 'Access approval could not be committed.', ['status' => 500]);
        }
        return ['project_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1];
    }

    public function revoke(string $uuid, string $reason, int $actorUserId): array|WP_Error
    {
        $cleanReason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if (!$this->validUuid($uuid) || $actorUserId < 1 || strlen($cleanReason) < 8 || (new SensitiveValueDetector())->violations($cleanReason) !== []) {
            return new WP_Error('smai_invalid_access_revocation', 'Access revocation request is invalid.', ['status' => 400]);
        }
        $project = $this->get($uuid);
        if ($project === null || !in_array((string) $project['state'], ['active','expiring'], true)) {
            return new WP_Error('smai_access_project_not_active', 'Access project is not active.', ['status' => 409]);
        }
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_access_transaction_failed', 'Access revocation transaction could not start.', ['status' => 500]);
        }
        $now = $this->db->now();
        $updated = $wpdb->update($this->db->table('access_projects'), [
            'state' => 'revoked',
            'revoked_at' => $now,
            'row_version' => (int) $project['row_version'] + 1,
            'updated_at' => $now,
        ], ['id' => (int) $project['id'], 'state' => (string) $project['state'], 'row_version' => (int) $project['row_version']]);
        if ($updated !== 1 || !$this->revokeChildren($uuid, $now)
            || !$this->audit->logInOpenTransaction('analytics_access_revoked', 'access_project', $uuid, 'success', ['reason' => $cleanReason], 'access_governance', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_project_conflict', 'Access revocation and dependent revocations could not be committed.', ['status' => 409]);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_access_commit_failed', 'Access revocation could not be committed.', ['status' => 500]);
        }
        return ['project_uuid' => $uuid, 'state' => 'revoked', 'row_version' => (int) $project['row_version'] + 1];
    }

    /** @param array<int,string> $fields */
    public function authorize(string $uuid, int $actorUserId, string $datasetRef, array $fields = [], ?string $purpose = null): bool
    {
        if (!$this->validUuid($uuid) || $actorUserId < 1 || preg_match('/^(?:metric:)?[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/', $datasetRef) !== 1) {
            return false;
        }
        $project = $this->get($uuid);
        if ($project === null || (string) $project['state'] !== 'active'
            || (int) $project['owner_user_id'] !== $actorUserId
            || strtotime((string) $project['expires_at']) <= time()
            || ($purpose !== null && !hash_equals(trim((string) $project['purpose']), trim($purpose)))) {
            return false;
        }
        $datasets = array_map('strval', Json::list((string) $project['datasets_json']));
        if (!in_array($datasetRef, $datasets, true) || !$this->referenceExists($datasetRef)) {
            return false;
        }
        $allowed = Json::object((string) $project['fields_json']);
        $allowedFields = array_map('strval', (array) ($allowed[$datasetRef] ?? []));
        foreach (array_unique(array_map('strval', $fields)) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                return false;
            }
        }
        return true;
    }

    public function expireDue(): int
    {
        $table = $this->db->table('access_projects');
        $now = $this->db->now();
        $projects = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE state IN ('active','expiring') AND expires_at<=%s ORDER BY id LIMIT 250",
            $now
        ), ARRAY_A);
        $count = 0;
        foreach (is_array($projects) ? $projects : [] as $project) {
            $wpdb = $this->db->wpdb();
            if ($wpdb->query('START TRANSACTION') === false) { continue; }
            $updated = $wpdb->update($table, [
                'state' => 'closed',
                'revoked_at' => $now,
                'row_version' => (int) $project['row_version'] + 1,
                'updated_at' => $now,
            ], ['id' => (int) $project['id'], 'state' => (string) $project['state'], 'row_version' => (int) $project['row_version']]);
            if ($updated === 1 && $this->revokeChildren((string) $project['project_uuid'], $now)
                && $this->audit->logInOpenTransaction('analytics_access_expired', 'access_project', (string) $project['project_uuid'], 'success', [], 'access_governance', null, null, 'system')) {
                if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); continue; }
                $count++;
            } else {
                $wpdb->query('ROLLBACK');
            }
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array
    {
        if (!$this->validUuid($uuid)) {
            return null;
        }
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('access_projects')}` WHERE project_uuid=%s",
            $uuid
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function revokeChildren(string $uuid, string $now): bool
    {
        $wpdb = $this->db->wpdb();
        $queries = [
            $wpdb->prepare("UPDATE `{$this->db->table('exports')}` SET state='revoked',token_hash=NULL,revoked_at=%s,updated_at=%s WHERE project_uuid=%s AND state IN ('requested','building','ready')", $now, $now, $uuid),
            $wpdb->prepare("DELETE p FROM `{$this->db->table('export_payloads')}` p INNER JOIN `{$this->db->table('exports')}` e ON e.export_uuid=p.export_uuid WHERE e.project_uuid=%s", $uuid),
            $wpdb->prepare("UPDATE `{$this->db->table('reports')}` SET state='revoked',next_run_at=NULL,updated_at=%s WHERE project_uuid=%s AND state IN ('draft','active','paused')", $now, $uuid),
            $wpdb->prepare("UPDATE `{$this->db->table('report_deliveries')}` d INNER JOIN `{$this->db->table('reports')}` r ON r.report_uuid=d.report_uuid SET d.state='revoked',d.token_hash=NULL,d.bundle_json=NULL,d.revoked_at=%s,d.updated_at=%s WHERE r.project_uuid=%s AND d.state IN ('queued','ready','sent')", $now, $now, $uuid),
        ];
        foreach ($queries as $query) {
            if ($wpdb->query($query) === false) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int,string> $fields */
    private function fieldsExist(string $reference, array $fields): bool
    {
        if ($fields === []) {
            return true;
        }
        if (str_starts_with($reference, 'metric:')) {
            $safe = ['metric_id','metric_version','window_start','window_end','dimensions','value','numerator','denominator','cohort_size','quality_status','data_through','freshness_seconds','caveats','uncertainty','definition_hash','source_owner','status'];
            return array_diff($fields, $safe) === [];
        }
        [$id, $version] = explode('@', $reference, 2);
        $dataset = (new DatasetCatalog($this->db))->published($id, $version);
        if (!is_array($dataset)) {
            return false;
        }
        $available = array_keys((array) (($dataset['definition']['fields'] ?? [])));
        return array_diff($fields, $available) === [];
    }

    private function referenceExists(string $reference): bool
    {
        $metric = str_starts_with($reference, 'metric:');
        $bare = $metric ? substr($reference, 7) : $reference;
        if (!str_contains($bare, '@')) {
            return false;
        }
        [$id, $version] = explode('@', $bare, 2);
        return $metric
            ? (new MetricCatalog($this->db))->active($id, $version) !== null
            : (new DatasetCatalog($this->db))->published($id, $version) !== null;
    }

    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/', $value, $match) !== 1 || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) { return null; }
        $timestamp = strtotime($value); return $timestamp === false ? null : $timestamp;
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
