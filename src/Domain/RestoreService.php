<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class RestoreService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $evidence */
    public function record(string $codeSha, array $evidence, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || preg_match('/^[a-f0-9]{40,64}$/', $codeSha) !== 1 || (new SensitiveValueDetector())->violations($evidence) !== []) {
            return new WP_Error('smai_invalid_restore_evidence', 'Restore evidence is invalid or contains sensitive values.', ['status' => 400]);
        }
        foreach (['backup_manifest_hash','backup_archive_hash','backup_created_at'] as $required) {
            if (!isset($evidence[$required]) || ($required !== 'backup_created_at' && preg_match('/^[a-f0-9]{64}$/', (string) $evidence[$required]) !== 1)) {
                return new WP_Error('smai_incomplete_restore_evidence', 'Restore evidence is incomplete.', ['status' => 400, 'field' => $required]);
            }
        }
        if (strtotime((string) $evidence['backup_created_at']) === false) {
            return new WP_Error('smai_invalid_restore_timestamp', 'Backup evidence timestamp is invalid.', ['status' => 400]);
        }
        $uuid = Uuid::v4();
        $catalogHash = $this->catalogHash();
        $checkpoints = $this->checkpointSnapshot();
        $deletionFloor = (int) $this->db->wpdb()->get_var("SELECT COALESCE(MAX(id),0) FROM `{$this->db->table('deletion_jobs')}`");
        $accessFloor = (int) $this->db->wpdb()->get_var("SELECT COALESCE(MAX(id),0) FROM `{$this->db->table('access_projects')}`");
        $ok = $this->db->wpdb()->insert($this->db->table('restore_points'), [
            'restore_uuid' => $uuid,
            'state' => 'recorded',
            'code_sha' => strtolower($codeSha),
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'catalog_hash' => $catalogHash,
            'checkpoints_json' => Json::canonical($checkpoints),
            'deletion_floor_id' => $deletionFloor,
            'access_floor_id' => $accessFloor,
            'evidence_json' => Json::canonical($evidence),
            'recorded_by' => $actorUserId,
            'created_at' => $this->db->now(),
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_restore_point_store_failed', 'Restore point could not be stored.', ['status' => 500]);
        }
        $this->audit->log('restore_point_recorded', 'restore_point', $uuid, 'success', ['code_sha' => $codeSha, 'catalog_hash' => $catalogHash], 'disaster_recovery', null, $actorUserId);
        return ['restore_uuid' => $uuid, 'state' => 'recorded', 'catalog_hash' => $catalogHash, 'checkpoint_hash' => hash('sha256', Json::canonical($checkpoints))];
    }

    public function verify(string $uuid, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('restore_points');
        $point = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE restore_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($point)) {
            return new WP_Error('smai_restore_point_not_found', 'Restore point was not found.', ['status' => 404]);
        }
        if ((int) $point['recorded_by'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Restore verification requires an independent actor.', ['status' => 403]);
        }
        (new AccessProjectService($this->db))->expireDue();
        $currentCodeSha = defined('SMAI_CODE_SHA') && is_string(SMAI_CODE_SHA) ? SMAI_CODE_SHA : (string) apply_filters('smai_current_code_sha', '');
        $checks = [
            'code_sha_matches' => $currentCodeSha !== '' && hash_equals((string) $point['code_sha'], $currentCodeSha),
            'schema_matches' => hash_equals((string) $point['schema_version'], (string) get_option('smai_schema_version', 'unknown')),
            'catalog_matches' => hash_equals((string) $point['catalog_hash'], $this->catalogHash()),
            'checkpoints_match' => hash_equals(hash('sha256', (string) $point['checkpoints_json']), hash('sha256', Json::canonical($this->checkpointSnapshot()))),
            'deletion_floor_applied' => $this->verifyDeletionFloor((int) $point['deletion_floor_id']),
            'deleted_subjects_absent' => $this->verifyDeletedSubjects((int) $point['deletion_floor_id']),
            'access_floor_applied' => $this->verifyAccessFloor((int) $point['access_floor_id']),
            'provider_restore_verified' => $this->verifyProviders($uuid),
        ];
        $verified = !in_array(false, $checks, true);
        $this->db->wpdb()->update($table, ['state' => $verified ? 'verified' : 'failed', 'verified_by' => $actorUserId, 'verified_at' => $this->db->now()], ['id' => (int) $point['id']]);
        $this->audit->log('warehouse_restore_verified', 'restore_point', $uuid, $verified ? 'success' : 'failed', $checks, 'disaster_recovery', null, $actorUserId);
        do_action('smai_restore_verification_completed', ['restore_uuid' => $uuid, 'verified' => $verified, 'checks' => $checks]);
        return ['restore_uuid' => $uuid, 'state' => $verified ? 'verified' : 'failed', 'checks' => $checks];
    }

    /** @return array<int,array<string,mixed>> */
    private function checkpointSnapshot(): array
    {
        $rows = $this->db->wpdb()->get_results("SELECT stream_ref,consumer_ref,contract_version,watermark_at,source_sequence,checkpoint_hash FROM `{$this->db->table('checkpoints')}` ORDER BY stream_ref,consumer_ref", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function catalogHash(): string
    {
        $parts = [];
        foreach ([
            ['event_schemas','event_name,event_version,schema_hash,state,row_version'],
            ['datasets','dataset_id,dataset_version,definition_hash,state,row_version'],
            ['metrics','metric_id,metric_version,definition_hash,state,row_version'],
            ['providers','provider_id,provider_version,state,region_code,row_version'],
            ['quality_rules','rule_id,rule_version,config_hash,state,row_version'],
            ['dashboard_definitions','dashboard_id,dashboard_version,definition_hash,state,row_version'],
        ] as [$tableName, $columns]) {
            $rows = $this->db->wpdb()->get_results("SELECT {$columns} FROM `{$this->db->table($tableName)}` ORDER BY id", ARRAY_A);
            $parts[$tableName] = is_array($rows) ? $rows : [];
        }
        return hash('sha256', Json::canonical($parts));
    }

    private function verifyDeletionFloor(int $floor): bool
    {
        if ($floor < 1) {return true;}
        return (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('deletion_jobs')}` WHERE id<=%d AND state<>'completed'", $floor)) === 0;
    }

    private function verifyDeletedSubjects(int $floor): bool
    {
        if ($floor < 1) {return true;}
        $jobs = $this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT deletion_key FROM `{$this->db->table('deletion_jobs')}` WHERE id<=%d AND state='completed'", $floor), ARRAY_A);
        foreach (is_array($jobs) ? $jobs : [] as $job) {
            $key = (string) $job['deletion_key'];
            foreach ([['events','deletion_key'],['dataset_rows','deletion_key'],['experiment_facts','subject_ref']] as [$table,$column]) {
                $count = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table($table)}` WHERE `{$column}`=%s", $key));
                if ($count !== 0) {return false;}
            }
        }
        return true;
    }

    private function verifyAccessFloor(int $floor): bool
    {
        if ($floor < 1) {return true;}
        $now = $this->db->now();
        $staleProjects = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('access_projects')}` WHERE id<=%d AND state='active' AND expires_at<=%s", $floor, $now));
        $staleExports = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('exports')}` e INNER JOIN `{$this->db->table('access_projects')}` p ON p.project_uuid=e.project_uuid WHERE p.id<=%d AND p.state<>'active' AND e.state IN ('requested','building','ready')", $floor));
        return $staleProjects === 0 && $staleExports === 0;
    }

    private function verifyProviders(string $uuid): bool
    {
        $providers = $this->db->wpdb()->get_results("SELECT provider_id,provider_version FROM `{$this->db->table('providers')}` WHERE state='active'", ARRAY_A);
        foreach (is_array($providers) ? $providers : [] as $provider) {
            if (in_array((string) $provider['provider_id'], ['local','local-wordpress'], true)) {continue;}
            $result = apply_filters('smai_verify_provider_restore', null, $provider['provider_id'], $provider['provider_version'], $uuid);
            if (!is_array($result) || ($result['verified'] ?? false) !== true || preg_match('/^[a-f0-9]{64}$/', (string) ($result['evidence_hash'] ?? '')) !== 1) {return false;}
        }
        return true;
    }
}
