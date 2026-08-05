<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\DatasetDefinitionValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use WP_Error;

final class DatasetCatalog
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function register(array $definition, int $actorUserId): array|WP_Error
    {
        $errors = (new DatasetDefinitionValidator())->errors($definition);
        if ($errors !== []) {
            return new WP_Error('smai_invalid_dataset', 'Dataset definition validation failed.', ['status' => 400, 'errors' => $errors]);
        }
        $json = Json::canonical($definition);
        $hash = hash('sha256', $json);
        $table = $this->db->table('datasets');
        $wpdb = $this->db->wpdb();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,definition_hash,state,row_version FROM `{$table}` WHERE dataset_id=%s AND dataset_version=%s",
            (string) $definition['dataset_id'],
            (string) $definition['dataset_version']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['definition_hash'], $hash)) {
                return ['id' => (int) $existing['id'], 'state' => (string) $existing['state'], 'row_version' => (int) $existing['row_version'], 'unchanged' => true];
            }
            return new WP_Error('smai_dataset_immutable', 'An existing dataset version cannot be changed.', ['status' => 409]);
        }
        $now = $this->db->now();
        $inserted = $wpdb->insert($table, [
            'dataset_id' => $definition['dataset_id'],
            'dataset_version' => $definition['dataset_version'],
            'name' => $definition['name'],
            'owner_module' => $definition['owner_module'],
            'state' => 'draft',
            'grain' => $definition['grain'],
            'definition_json' => $json,
            'definition_hash' => $hash,
            'privacy_class' => $definition['privacy_class'],
            'retention_days' => (int) $definition['retention_days'],
            'region_code' => $definition['region_code'],
            'provider_id' => $definition['provider_id'],
            'quality_status' => 'unknown',
            'row_version' => 1,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return new WP_Error('smai_dataset_store_failed', 'Dataset could not be stored.', ['status' => 500]);
        }
        $id = (int) $wpdb->insert_id;
        $this->audit->log('dataset_registered', 'dataset', (string) $id, 'success', [
            'dataset_id' => $definition['dataset_id'],
            'dataset_version' => $definition['dataset_version'],
            'definition_hash' => $hash,
        ], 'analytics_governance', null, $actorUserId);
        return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
    }

    /** @return array<string,mixed>|null */
    public function published(string $datasetId, string $version): ?array
    {
        $table = $this->db->table('datasets');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE dataset_id=%s AND dataset_version=%s AND state='published'",
            $datasetId,
            $version
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['definition'] = Json::object((string) $row['definition_json']);
        return $row;
    }
}
