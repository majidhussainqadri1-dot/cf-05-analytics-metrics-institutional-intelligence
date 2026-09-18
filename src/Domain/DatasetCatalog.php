<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\DatasetDefinitionValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
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
        if (!RuntimeGate::catalogEnabled()) {
            return new WP_Error('smai_catalog_disabled', 'Catalog mutation is disabled until the governed catalog runtime is enabled.', ['status' => 503]);
        }
        $validator = new DatasetDefinitionValidator();
        $errors = $validator->errors($definition);
        if ($actorUserId < 1 || $errors !== []) {
            return new WP_Error('smai_invalid_dataset', 'Dataset definition validation failed.', ['status' => 400, 'errors' => $errors]);
        }
        $definition = $validator->normalize($definition);

        $sourceSchemas = [];
        $privacyRank = ['C1' => 1, 'C2' => 2, 'C3' => 3];
        $maximumPrivacy = 0;
        $minimumRetention = 730;
        $schemaRegistry = new EventSchemaRegistry($this->db);
        foreach ((array) $definition['sources'] as $source) {
            $schema = $schemaRegistry->active((string) $source['event_name'], (string) $source['event_version']);
            if ($schema === null) {
                return new WP_Error('smai_dataset_source_unavailable', 'Every dataset source must be an active event contract.', ['status' => 409]);
            }
            $sourceSchemas[] = (array) $schema['schema'];
            $maximumPrivacy = max($maximumPrivacy, $privacyRank[(string) $schema['privacy_class']] ?? 0);
            $minimumRetention = min($minimumRetention, (int) $schema['retention_days']);
        }
        if (($privacyRank[(string) $definition['privacy_class']] ?? 0) < $maximumPrivacy) {
            return new WP_Error('smai_dataset_privacy_downgrade', 'Dataset privacy class cannot silently downgrade a source contract.', ['status' => 409]);
        }
        if ((int) $definition['retention_days'] > $minimumRetention) {
            return new WP_Error('smai_dataset_retention_exceeds_source', 'Dataset retention cannot exceed the shortest approved source retention.', ['status' => 409]);
        }
        $mappingErrors = $this->mappingErrors((array) $definition['fields'], $sourceSchemas);
        if ($mappingErrors !== []) {
            return new WP_Error('smai_dataset_mapping_invalid', 'Dataset field mappings do not match the active source contracts.', ['status' => 409, 'errors' => $mappingErrors]);
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
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_dataset_transaction_failed', 'Dataset registration transaction could not start.', ['status' => 500]);
        }
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
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_dataset_store_failed', 'Dataset could not be stored.', ['status' => 500]);
        }
        $id = (int) $wpdb->insert_id;
        if (!$this->audit->logInOpenTransaction(
            'dataset_registered',
            'dataset',
            (string) $id,
            'success',
            ['dataset_id' => $definition['dataset_id'], 'dataset_version' => $definition['dataset_version'], 'definition_hash' => $hash],
            'analytics_governance',
            null,
            $actorUserId
        )) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_dataset_audit_failed', 'Dataset registration was rolled back because audit evidence was unavailable.', ['status' => 503]);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_dataset_commit_failed', 'Dataset registration could not be committed.', ['status' => 500]);
        }
        return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
    }

    /** @return array<string,mixed>|null */
    public function published(string $datasetId, string $version): ?array
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s AND state='published'",
            $datasetId,
            $version
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['definition'] = Json::object((string) $row['definition_json']);
        return $row['definition'] === [] ? null : $row;
    }

    /** @param array<string,mixed> $fields @param array<int,array<string,mixed>> $schemas @return array<int,string> */
    private function mappingErrors(array $fields, array $schemas): array
    {
        $errors = [];
        $safeEnvelope = [
            'occurred_at','recorded_at','source_module','source_version','source_environment',
            'purpose','actor_ref','object_ref','deletion_key','is_late',
        ];
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            [$scope, $sourceField] = array_pad(explode('.', (string) ($field['from'] ?? ''), 2), 2, '');
            if ($scope === 'event') {
                if (!in_array($sourceField, $safeEnvelope, true)) {
                    $errors[] = 'unsafe_event_mapping_' . $name;
                }
                continue;
            }
            $present = 0;
            foreach ($schemas as $schema) {
                $sourceDefinition = is_array($schema['fields'][$sourceField] ?? null) ? $schema['fields'][$sourceField] : null;
                if ($sourceDefinition === null) {
                    continue;
                }
                $present++;
                if (!$this->typesCompatible((string) ($sourceDefinition['type'] ?? ''), (string) ($field['type'] ?? ''))) {
                    $errors[] = 'incompatible_mapping_' . $name;
                }
            }
            if ($present === 0 || (($field['required'] ?? false) === true && $present !== count($schemas))) {
                $errors[] = 'missing_source_mapping_' . $name;
            }
        }
        return array_values(array_unique($errors));
    }

    private function typesCompatible(string $eventType, string $datasetType): bool
    {
        $map = [
            'boolean' => ['boolean'],
            'integer' => ['integer','number'],
            'number' => ['number'],
            'enum' => ['string'],
            'safe_string' => ['string'],
            'timestamp' => ['timestamp'],
            'pseudonymous_ref' => ['pseudonymous_ref'],
        ];
        return in_array($datasetType, $map[$eventType] ?? [], true);
    }
}
