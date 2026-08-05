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
        $normalized = $this->validator->normalize($definition);
        $errors = $this->validator->errors($normalized);
        if ($actorUserId < 1 || $errors !== []) {
            return new WP_Error('smai_invalid_metric', 'Metric definition validation failed.', ['status' => 400, 'errors' => $errors]);
        }
        $source = (array) $normalized['source'];
        if ((new DatasetCatalog($this->db))->published((string) $source['dataset_id'], (string) $source['dataset_version']) === null) {
            return new WP_Error('smai_metric_source_unpublished', 'Metric source dataset must be published.', ['status' => 409]);
        }
        $json = Json::canonical($normalized);
        $hash = hash('sha256', $json);
        $table = $this->db->table('metrics');
        $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT id,definition_hash,state FROM `{$table}` WHERE metric_id=%s AND metric_version=%s",
            $normalized['metric_id'],
            $normalized['metric_version']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['definition_hash'], $hash)) {
                return ['id' => (int) $existing['id'], 'status' => 'unchanged', 'state' => $existing['state'], 'definition_hash' => $hash];
            }
            return new WP_Error('smai_metric_immutable', 'An existing metric version cannot be silently changed.', ['status' => 409]);
        }
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($table, [
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
        if ($ok !== 1) {
            return new WP_Error('smai_metric_store_failed', 'Metric definition could not be stored.', ['status' => 500]);
        }
        $id = (int) $this->db->wpdb()->insert_id;
        $this->audit->log('metric_registered', 'metric_definition', (string) $id, 'success', ['metric_id' => $normalized['metric_id'], 'metric_version' => $normalized['metric_version'], 'definition_hash' => $hash], 'institutional_measurement', null, $actorUserId);
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
}
