<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
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
        if ($errors !== []) {
            return new WP_Error('smai_invalid_metric', 'Metric definition validation failed.', ['status' => 400, 'errors' => $errors]);
        }

        $normalized = $this->validator->normalize($definition);
        $json = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', (string) $json);
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('metrics');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, definition_hash, state FROM `{$table}` WHERE metric_id=%s AND metric_version=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $normalized['metric_id'],
            $normalized['metric_version']
        ), ARRAY_A);

        if (is_array($existing)) {
            if (hash_equals((string) $existing['definition_hash'], $hash)) {
                return ['id' => (int) $existing['id'], 'status' => 'unchanged', 'definition_hash' => $hash];
            }
            return new WP_Error('smai_metric_immutable', 'An existing metric version cannot be silently changed. Register a new version.', ['status' => 409]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($table, [
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
        $id = (int) $wpdb->insert_id;
        $this->audit->log('metric_registered', 'metric_definition', (string) $id, 'success', [
            'metric_id' => $normalized['metric_id'],
            'metric_version' => $normalized['metric_version'],
            'definition_hash' => $hash,
        ], 'institutional_measurement', null, $actorUserId);

        return ['id' => $id, 'status' => 'draft', 'definition_hash' => $hash];
    }

    /** @return array<string,mixed>|null */
    public function active(string $metricId, string $version): ?array
    {
        $table = $this->db->table('metrics');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND state='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $metricId,
            $version
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $definition = json_decode((string) $row['definition_json'], true);
        return is_array($definition) ? array_merge($row, ['definition' => $definition]) : null;
    }
}
