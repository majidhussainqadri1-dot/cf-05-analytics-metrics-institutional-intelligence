<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\EventSchemaValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;

final class EventSchemaRegistry
{
    private Database $db;
    private EventSchemaValidator $validator;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->validator = new EventSchemaValidator();
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $schema */
    public function register(array $schema, int $actorUserId): array|WP_Error
    {
        $errors = $this->validator->errors($schema);
        if ($errors !== []) {
            return new WP_Error('smai_invalid_event_schema', 'Event schema validation failed.', ['status' => 400, 'errors' => $errors]);
        }

        $normalized = $this->validator->normalize($schema);
        $json = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', (string) $json);
        $now = gmdate('Y-m-d H:i:s');
        $table = $this->db->table('event_schemas');
        $wpdb = $this->db->wpdb();

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, schema_hash, row_version, state FROM `{$table}` WHERE event_name=%s AND event_version=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $normalized['event_name'],
            (string) $normalized['event_version']
        ), ARRAY_A);

        if (is_array($existing)) {
            if (hash_equals((string) $existing['schema_hash'], $hash)) {
                return ['id' => (int) $existing['id'], 'status' => 'unchanged', 'schema_hash' => $hash];
            }
            return new WP_Error('smai_contract_immutable', 'An existing event contract version cannot be silently changed. Register a new version.', ['status' => 409]);
        }

        $inserted = $wpdb->insert($table, [
            'event_name' => $normalized['event_name'],
            'event_version' => $normalized['event_version'],
            'owner_module' => $normalized['owner_module'],
            'state' => 'proposed',
            'purpose' => $normalized['purpose'],
            'privacy_class' => $normalized['privacy_class'],
            'retention_days' => (int) $normalized['retention_days'],
            'deletion_key_field' => $normalized['deletion_key_field'],
            'schema_json' => $json,
            'schema_hash' => $hash,
            'row_version' => 1,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return new WP_Error('smai_schema_store_failed', 'Event schema could not be stored.', ['status' => 500]);
        }

        $id = (int) $wpdb->insert_id;
        $this->audit->log('event_schema_registered', 'event_schema', (string) $id, 'success', [
            'event_name' => $normalized['event_name'],
            'event_version' => $normalized['event_version'],
            'schema_hash' => $hash,
        ], 'analytics_governance', null, $actorUserId);

        return ['id' => $id, 'status' => 'proposed', 'schema_hash' => $hash];
    }

    /** @return array<string,mixed>|null */
    public function active(string $eventName, string $eventVersion): ?array
    {
        $table = $this->db->table('event_schemas');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE event_name=%s AND event_version=%s AND state='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $eventName,
            $eventVersion
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) $row['schema_json'], true);
        return is_array($decoded) ? array_merge($row, ['schema' => $decoded]) : null;
    }
}
