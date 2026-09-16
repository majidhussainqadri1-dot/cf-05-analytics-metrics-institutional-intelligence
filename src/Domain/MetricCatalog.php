<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use WP_Error;

final class MetricCatalog
{
    private Database $db;
    private MetricDefinitionValidator $validator;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->validator = new MetricDefinitionValidator();
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function register(array $definition, int $actorUserId): array|WP_Error
    {
        $errors = $this->validator->errors($definition);
        if ($actorUserId < 1 || $errors !== []) {
            return new WP_Error('smai_invalid_metric', 'Metric definition validation failed.', ['status' => 400, 'errors' => $errors]);
        }
        $normalized = $this->validator->normalize($definition);
        $source = (array) $normalized['source'];
        $dataset = (new DatasetCatalog($this->db))->published((string) $source['dataset_id'], (string) $source['dataset_version']);
        if ($dataset === null) {
            return new WP_Error('smai_metric_source_unpublished', 'Metric source dataset must be published.', ['status' => 409]);
        }
        $datasetDefinition = is_array($dataset['definition'] ?? null) ? $dataset['definition'] : [];
        $datasetFields = is_array($datasetDefinition['fields'] ?? null) ? array_keys($datasetDefinition['fields']) : [];
        $referencedFields = array_values(array_unique(array_merge(
            array_map('strval', (array) ($normalized['dimensions'] ?? [])),
            $this->calculationFields((array) $normalized['calculation']),
            !empty($normalized['cohort_field']) ? [(string) $normalized['cohort_field']] : []
        )));
        $unknownFields = array_values(array_diff($referencedFields, $datasetFields));
        if ($unknownFields !== []) {
            return new WP_Error('smai_metric_unknown_source_field', 'Metric references fields absent from the published dataset contract.', ['status' => 409, 'fields' => $unknownFields]);
        }
        $rank = ['C1' => 1, 'C2' => 2, 'C3' => 3];
        if (($rank[(string) $normalized['privacy_class']] ?? 0) < ($rank[(string) $dataset['privacy_class']] ?? 0)) {
            return new WP_Error('smai_metric_privacy_downgrade', 'Metric privacy class cannot silently downgrade its source dataset.', ['status' => 409]);
        }

        $json = Json::canonical($normalized);
        $hash = hash('sha256', $json);
        $table = $this->db->table('metrics');
        $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT id,definition_hash,state,row_version FROM `{$table}` WHERE metric_id=%s AND metric_version=%s",
            $normalized['metric_id'],
            $normalized['metric_version']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['definition_hash'], $hash)) {
                return [
                    'id' => (int) $existing['id'],
                    'status' => 'unchanged',
                    'state' => $existing['state'],
                    'row_version' => (int) $existing['row_version'],
                    'definition_hash' => $hash,
                ];
            }
            return new WP_Error('smai_metric_immutable', 'An existing metric version cannot be silently changed.', ['status' => 409]);
        }

        $now = $this->db->now();
        $inserted = $this->db->wpdb()->insert($table, [
            'metric_id' => $normalized['metric_id'],
            'metric_version' => $normalized['metric_version'],
            'name' => $normalized['name'],
            'owner_module' => $normalized['owner_module'],
            'state' => 'draft',
            'business_question' => $normalized['business_question'],
            'definition_json' => $json,
            'definition_hash' => $hash,
            'privacy_class' => $normalized['privacy_class'],
            'minimum_cohort' => max(5, (int) $normalized['minimum_cohort']),
            'effective_from' => null,
            'effective_to' => null,
            'row_version' => 1,
            'created_by' => $actorUserId,
            'approved_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return new WP_Error('smai_metric_store_failed', 'Metric definition could not be stored.', ['status' => 500]);
        }
        $id = (int) $this->db->wpdb()->insert_id;
        if (!$this->audit->log(
            'metric_registered',
            'metric_definition',
            (string) $id,
            'success',
            ['metric_id' => $normalized['metric_id'], 'metric_version' => $normalized['metric_version'], 'definition_hash' => $hash],
            'institutional_measurement',
            null,
            $actorUserId
        )) {
            $this->db->wpdb()->delete($table, ['id' => $id, 'state' => 'draft', 'row_version' => 1]);
            return new WP_Error('smai_metric_audit_failed', 'Metric registration was rolled back because audit evidence was unavailable.', ['status' => 503]);
        }
        return ['id' => $id, 'status' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
    }

    /** @return array<string,mixed>|null */
    public function active(string $metricId, string $version): ?array
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('metrics')}` WHERE metric_id=%s AND metric_version=%s AND state='active'",
            $metricId,
            $version
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $definition = Json::object((string) $row['definition_json']);
        return $definition === [] ? null : array_merge($row, ['definition' => $definition]);
    }

    /** @param array<string,mixed> $calculation @return array<int,string> */
    private function calculationFields(array $calculation): array
    {
        $fields = [];
        if (isset($calculation['field']) && is_string($calculation['field'])) {
            $fields[] = $calculation['field'];
        }
        foreach (['filters','numerator_filters','denominator_filters'] as $key) {
            foreach ((array) ($calculation[$key] ?? []) as $filter) {
                if (is_array($filter) && isset($filter['field']) && is_string($filter['field'])) {
                    $fields[] = $filter['field'];
                }
            }
        }
        return $fields;
    }
}
