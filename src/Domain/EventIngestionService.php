<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use WP_Error;

final class EventIngestionService
{
    private Database $db;
    private EventSchemaRegistry $registry;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->registry = new EventSchemaRegistry($db);
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $event */
    public function ingest(array $event, string $service): array|WP_Error
    {
        if (!RuntimeGate::ingestionEnabled()) {
            return new WP_Error('smai_ingestion_disabled', 'CF-05 event ingestion is disabled until activation evidence is approved.', ['status' => 503]);
        }

        foreach (['event_id','event_name','event_version','source_module','source_environment','occurred_at','recorded_at','purpose','properties'] as $required) {
            if (!array_key_exists($required, $event)) {
                return $this->quarantine($event, 'missing_envelope_field', $required, $service, 400);
            }
        }
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', (string) $event['event_id'])) {
            return $this->quarantine($event, 'invalid_event_id', 'event_id', $service, 400);
        }
        $occurredTimestamp = strtotime((string) $event['occurred_at']);
        $recordedTimestamp = strtotime((string) $event['recorded_at']);
        if ($occurredTimestamp === false || $recordedTimestamp === false) {
            return $this->quarantine($event, 'invalid_event_time', 'occurred_at or recorded_at', $service, 400);
        }
        if ($occurredTimestamp > time() + 300 || $recordedTimestamp > time() + 300) {
            return $this->quarantine($event, 'future_event_time', 'Event time exceeds the allowed clock-skew window.', $service, 400);
        }

        $schemaRow = $this->registry->active((string) $event['event_name'], (string) $event['event_version']);
        if ($schemaRow === null) {
            return $this->quarantine($event, 'unknown_contract', 'No active event schema.', $service, 422);
        }
        $schema = (array) $schemaRow['schema'];
        if (!hash_equals((string) $schema['owner_module'], (string) $event['source_module'])) {
            return $this->quarantine($event, 'owner_mismatch', 'Source module is not the schema owner.', $service, 403);
        }
        if (!hash_equals((string) $schema['purpose'], (string) $event['purpose'])) {
            return $this->quarantine($event, 'purpose_mismatch', 'Event purpose is not approved by the contract.', $service, 403);
        }

        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_pseudonym_key_missing', 'Pseudonymization key is not configured.', ['status' => 503]);
        }

        $gateway = new PrivacyGateway(SMAI_PSEUDONYM_KEY);
        $processed = $gateway->process($event, $schema);
        if (!$processed['accepted']) {
            return $this->quarantine($event, 'privacy_gateway_rejected', implode(',', $processed['errors']), $service, 422);
        }

        $propertiesJson = wp_json_encode($processed['properties'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $table = $this->db->table('events');
        $wpdb = $this->db->wpdb();
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (event_id,event_name,event_version,source_module,source_environment,source_sequence,occurred_at,recorded_at,actor_ref,object_ref,deletion_key,purpose,consent_version,policy_version,trace_id,properties_json,payload_hash,created_at) VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $event['event_id'],
            (string) $event['event_name'],
            (string) $event['event_version'],
            (string) $event['source_module'],
            (string) $event['source_environment'],
            isset($event['source_sequence']) ? (int) $event['source_sequence'] : 0,
            gmdate('Y-m-d H:i:s', $occurredTimestamp),
            gmdate('Y-m-d H:i:s', $recordedTimestamp),
            $processed['actor_ref'],
            $processed['object_ref'],
            $processed['deletion_key'],
            (string) $event['purpose'],
            isset($event['consent_version']) ? (string) $event['consent_version'] : null,
            isset($event['policy_version']) ? (string) $event['policy_version'] : null,
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            $propertiesJson,
            $payloadHash,
            $now
        ));

        if ($inserted === false) {
            return new WP_Error('smai_event_store_failed', 'Event could not be stored.', ['status' => 500]);
        }

        $status = $inserted === 0 ? 'duplicate_ignored' : 'accepted';
        $this->audit->log('analytics_event_ingested', 'analytics_event', (string) $event['event_id'], $status, [
            'event_name' => $event['event_name'],
            'event_version' => $event['event_version'],
            'source_module' => $event['source_module'],
            'service' => $service,
            'payload_hash' => $payloadHash,
        ], (string) $event['purpose'], isset($event['trace_id']) ? (string) $event['trace_id'] : null, null, 'service');

        return ['event_id' => (string) $event['event_id'], 'status' => $status, 'payload_hash' => $payloadHash];
    }

    /** @param array<string,mixed> $event */
    private function quarantine(array $event, string $code, string $detail, string $service, int $status): WP_Error
    {
        $table = $this->db->table('quarantine');
        $now = gmdate('Y-m-d H:i:s');
        $sample = [
            'keys' => array_slice(array_keys($event), 0, 30),
            'property_keys' => array_slice(array_keys(is_array($event['properties'] ?? null) ? $event['properties'] : []), 0, 30),
        ];
        $hash = hash('sha256', wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->db->wpdb()->insert($table, [
            'event_id' => isset($event['event_id']) ? substr((string) $event['event_id'], 0, 64) : null,
            'event_name' => isset($event['event_name']) ? substr((string) $event['event_name'], 0, 190) : null,
            'event_version' => isset($event['event_version']) ? substr((string) $event['event_version'], 0, 32) : null,
            'source_module' => isset($event['source_module']) ? substr((string) $event['source_module'], 0, 100) : null,
            'reason_code' => substr($code, 0, 100),
            'reason_detail' => substr($detail, 0, 255),
            'redacted_sample' => wp_json_encode($sample),
            'payload_hash' => $hash,
            'status' => 'open',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->log('analytics_event_quarantined', 'quarantine', isset($event['event_id']) ? (string) $event['event_id'] : null, 'rejected', [
            'reason_code' => $code,
            'service' => $service,
            'payload_hash' => $hash,
        ], isset($event['purpose']) ? (string) $event['purpose'] : null, isset($event['trace_id']) ? (string) $event['trace_id'] : null, null, 'service');

        return new WP_Error('smai_event_quarantined', 'Event was quarantined.', ['status' => $status, 'reason_code' => $code]);
    }
}
