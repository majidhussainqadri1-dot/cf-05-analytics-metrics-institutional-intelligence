<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
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
        $expiry = strtotime($expiresAt);
        if ($actorUserId < 1 || strlen(trim($name)) < 3 || strlen(trim($purpose)) < 12
            || $expiry === false || $expiry <= time() || $expiry > time() + 366 * DAY_IN_SECONDS
            || $datasets === [] || count($datasets) > 50) {
            return new WP_Error('smai_invalid_access_request', 'Analytics access request is invalid.', ['status' => 400]);
        }
        $cleanDatasets = [];
        foreach ($datasets as $dataset) {
            if (preg_match('/^(?:metric:)?[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/', $dataset) !== 1) {
                return new WP_Error('smai_invalid_dataset_ref', 'Dataset reference is invalid.', ['status' => 400]);
            }
            $cleanDatasets[] = $dataset;
        }
        $cleanFields = [];
        foreach ($fields as $dataset => $list) {
            if (!in_array($dataset, $cleanDatasets, true) || !is_array($list) || count($list) > 100) {
                return new WP_Error('smai_invalid_project_fields', 'Project fields are invalid.', ['status' => 400]);
            }
            $cleanFields[$dataset] = [];
            foreach ($list as $field) {
                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
                    return new WP_Error('smai_invalid_project_field', 'Project field is invalid.', ['status' => 400]);
                }
                $cleanFields[$dataset][] = $field;
            }
        }

        $uuid = Uuid::v4();
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('access_projects'), [
            'project_uuid' => $uuid,
            'name' => Text::truncate(trim(wp_strip_all_tags($name)), 190),
            'purpose' => Text::truncate(trim(wp_strip_all_tags($purpose)), 2000),
            'state' => 'requested',
            'owner_user_id' => $actorUserId,
            'datasets_json' => Json::canonical(array_values(array_unique($cleanDatasets))),
            'fields_json' => Json::canonical($cleanFields),
            'training_confirmed' => $trainingConfirmed ? 1 : 0,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry),
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_access_project_store_failed', 'Access project could not be stored.', ['status' => 500]);
        }
        return ['project_uuid' => $uuid, 'state' => 'requested', 'row_version' => 1];
    }

    public function approve(string $uuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $project = $this->get($uuid);
        if ($project === null || (string) $project['state'] !== 'requested' || (int) $project['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_access_project_stale', 'Access project is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $project['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Project owner cannot approve their own access.', ['status' => 403]);
        }
        if ((int) $project['training_confirmed'] !== 1) {
            return new WP_Error('smai_training_required', 'Required privacy training is not confirmed.', ['status' => 409]);
        }
        $updated = $this->db->wpdb()->update($this->db->table('access_projects'), [
            'state' => 'active',
            'approved_by' => $actorUserId,
            'approved_at' => $this->db->now(),
            'reviewed_at' => $this->db->now(),
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $project['id'], 'state' => 'requested', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_access_project_conflict', 'Access project changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('analytics_access_granted', 'access_project', $uuid, 'success', [
            'owner_user_id' => (int) $project['owner_user_id'],
            'expires_at' => $project['expires_at'],
        ], 'access_governance', null, $actorUserId);
        return ['project_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1];
    }

    public function revoke(string $uuid, string $reason, int $actorUserId): array|WP_Error
    {
        $project = $this->get($uuid);
        if ($project === null || !in_array((string) $project['state'], ['active','expiring'], true)) {
            return new WP_Error('smai_access_project_not_active', 'Access project is not active.', ['status' => 409]);
        }
        $updated = $this->db->wpdb()->update($this->db->table('access_projects'), [
            'state' => 'revoked',
            'revoked_at' => $this->db->now(),
            'row_version' => (int) $project['row_version'] + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $project['id'], 'row_version' => (int) $project['row_version']]);
        if ($updated !== 1) {
            return new WP_Error('smai_access_project_conflict', 'Access project changed concurrently.', ['status' => 409]);
        }
        $this->revokeChildren($uuid);
        $this->audit->log('analytics_access_revoked', 'access_project', $uuid, 'success', [
            'reason' => Text::truncate(wp_strip_all_tags($reason), 500),
        ], 'access_governance', null, $actorUserId);
        return ['project_uuid' => $uuid, 'state' => 'revoked'];
    }

    public function authorize(string $uuid, int $actorUserId, string $datasetRef, array $fields = [], ?string $purpose = null): bool
    {
        $project = $this->get($uuid);
        if ($project === null || (string) $project['state'] !== 'active'
            || (int) $project['owner_user_id'] !== $actorUserId
            || strtotime((string) $project['expires_at']) <= time()
            || ($purpose !== null && !hash_equals(trim((string) $project['purpose']), trim($purpose)))) {
            return false;
        }
        $datasets = Json::list((string) $project['datasets_json']);
        if (!in_array($datasetRef, array_map('strval', $datasets), true)) {
            return false;
        }
        $allowed = Json::object((string) $project['fields_json']);
        $allowedFields = array_map('strval', (array) ($allowed[$datasetRef] ?? []));
        foreach ($fields as $field) {
            if (!in_array((string) $field, $allowedFields, true)) {
                return false;
            }
        }
        return true;
    }

    public function expireDue(): int
    {
        $table = $this->db->table('access_projects');
        $now = $this->db->now();
        $projects = $this->db->wpdb()->get_col($this->db->wpdb()->prepare(
            "SELECT project_uuid FROM `{$table}` WHERE state IN ('active','expiring') AND expires_at<=%s",
            $now
        ));
        $count = 0;
        foreach (is_array($projects) ? $projects : [] as $uuid) {
            $project = $this->get((string) $uuid);
            $updated = $this->db->wpdb()->update($table, [
                'state' => 'closed',
                'revoked_at' => $now,
                'row_version' => is_array($project) ? (int) $project['row_version'] + 1 : 1,
                'updated_at' => $now,
            ], ['project_uuid' => $uuid, 'state' => is_array($project) ? (string) $project['state'] : 'active']);
            if ($updated === 1) {
                $count++;
                $this->revokeChildren((string) $uuid);
            }
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array
    {
        $table = $this->db->table('access_projects');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE project_uuid=%s",
            $uuid
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function revokeChildren(string $uuid): void
    {
        $now = $this->db->now();
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('exports')}` SET state='revoked',token_hash=NULL,revoked_at=%s,updated_at=%s WHERE project_uuid=%s AND state IN ('requested','building','ready')",
            $now,
            $now,
            $uuid
        ));
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "DELETE p FROM `{$this->db->table('export_payloads')}` p INNER JOIN `{$this->db->table('exports')}` e ON e.export_uuid=p.export_uuid WHERE e.project_uuid=%s",
            $uuid
        ));
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('reports')}` SET state='revoked',updated_at=%s WHERE project_uuid=%s AND state IN ('draft','active','paused')",
            $now,
            $uuid
        ));
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('report_deliveries')}` d INNER JOIN `{$this->db->table('reports')}` r ON r.report_uuid=d.report_uuid SET d.state='revoked',d.token_hash=NULL,d.bundle_json=NULL,d.revoked_at=%s,d.updated_at=%s WHERE r.project_uuid=%s AND d.state IN ('queued','ready','sent')",
            $now,
            $now,
            $uuid
        ));
    }
}
