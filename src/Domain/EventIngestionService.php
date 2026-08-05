<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
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
        foreach ([
            'event_id','event_name','event_version','source_module','source_version',
            'source_environment','occurred_at','recorded_at','purpose','properties',
        ] as $required) {
            if (!array_key_exists($required, $event)) {
                return $this->quarantine($event, 'missing_envelope_field', $required, $service, 400);
            }
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', (string) $event['event_id']) !== 1) {
            return $this->quarantine($event, 'invalid_event_id', 'event_id', $service, 400);
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) $event['source_module']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $event['source_version']) !== 1
            || !in_array((string) $event['source_environment'], ['production','staging','development','local'], true)) {
            return $this->quarantine($event, 'invalid_source_identity', 'source identity', $service, 400);
        }
        $authorizedSource = (string) apply_filters('smai_service_source_module', $service, $service);
        if (!hash_equals($authorizedSource, (string) $event['source_module'])) {
            return $this->quarantine($event, 'service_source_mismatch', 'Service cannot emit for this source module.', $service, 403);
        }
        $occurred = strtotime((string) $event['occurred_at']);
        $recorded = strtotime((string) $event['recorded_at']);
        if ($occurred === false || $recorded === false) {
            return $this->quarantine($event, 'invalid_event_time', 'occurred_at or recorded_at', $service, 400);
        }
        if ($occurred > time() + 300 || $recorded > time() + 300) {
            return $this->quarantine($event, 'future_event_time', 'Event time exceeds the allowed clock-skew window.', $service, 400);
        }
        if ($recorded + 300 < $occurred) {
            return $this->quarantine($event, 'invalid_recording_order', 'recorded_at cannot materially precede occurred_at.', $service, 400);
        }
        foreach (['consent_version' => 64, 'policy_version' => 64, 'trace_id' => 100] as $field => $maxLength) {
            if (isset($event[$field]) && (!is_scalar($event[$field]) || strlen((string) $event[$field]) > $maxLength)) {
                return $this->quarantine($event, 'invalid_envelope_metadata', $field, $service, 400);
            }
        }
        if (!is_array($event['properties'])) {
            return $this->quarantine($event, 'invalid_properties', 'properties must be an object.', $service, 400);
        }
        if (isset($event['source_sequence']) && (!is_int($event['source_sequence']) || $event['source_sequence'] < 1)) {
            return $this->quarantine($event, 'invalid_source_sequence', 'source_sequence', $service, 400);
        }
        if (isset($event['correction_of_event_id']) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', (string) $event['correction_of_event_id']) !== 1) {
            return $this->quarantine($event, 'invalid_correction_reference', 'correction_of_event_id', $service, 400);
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
        $allowedRegions = is_array($schema['allowed_regions'] ?? null) ? $schema['allowed_regions'] : [];
        if ($allowedRegions !== [] && !in_array((string) ($event['region_code'] ?? ''), array_map('strval', $allowedRegions), true)) {
            return $this->quarantine($event, 'region_not_allowed', 'Event region is not approved.', $service, 403);
        }
        $allowedProviders = is_array($schema['allowed_providers'] ?? null) ? $schema['allowed_providers'] : [];
        if ($allowedProviders !== [] && !in_array((string) ($event['provider_id'] ?? ''), array_map('strval', $allowedProviders), true)) {
            return $this->quarantine($event, 'provider_not_allowed', 'Event provider is not approved.', $service, 403);
        }
        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_pseudonym_key_missing', 'Pseudonymization key is not configured.', ['status' => 503]);
        }

        $gateway = new PrivacyGateway(SMAI_PSEUDONYM_KEY);
        $processed = $gateway->process($event, $schema);
        if (!$processed['accepted']) {
            return $this->quarantine($event, 'privacy_gateway_rejected', implode(',', $processed['errors']), $service, 422);
        }

        if (!empty($event['correction_of_event_id'])) {
            $corrected = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                "SELECT event_name,event_version,source_module,occurred_at FROM `{$this->db->table('events')}` WHERE event_id=%s",
                (string) $event['correction_of_event_id']
            ), ARRAY_A);
            if (!is_array($corrected)
                || !hash_equals((string) $corrected['event_name'], (string) $event['event_name'])
                || !hash_equals((string) $corrected['event_version'], (string) $event['event_version'])
                || !hash_equals((string) $corrected['source_module'], (string) $event['source_module'])
                || strtotime((string) $corrected['occurred_at']) > $occurred) {
                return $this->quarantine($event, 'invalid_correction_reference', 'Correction must reference an earlier event in the same owner contract.', $service, 409);
            }
        }
        $outOfOrder = false;
        if (isset($event['source_sequence']) && (int) $event['source_sequence'] > 0) {
            $sequenceOwner = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                "SELECT event_id FROM `{$this->db->table('events')}` WHERE source_module=%s AND source_environment=%s AND source_sequence=%d LIMIT 1",
                (string) $event['source_module'],
                (string) $event['source_environment'],
                (int) $event['source_sequence']
            ), ARRAY_A);
            if (is_array($sequenceOwner) && !hash_equals((string) $sequenceOwner['event_id'], (string) $event['event_id'])) {
                return $this->quarantine($event, 'source_sequence_collision', 'Source sequence is already bound to another event.', $service, 409);
            }
            $maxSequence = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                "SELECT COALESCE(MAX(source_sequence),0) FROM `{$this->db->table('events')}` WHERE source_module=%s AND source_environment=%s",
                (string) $event['source_module'],
                (string) $event['source_environment']
            ));
            $outOfOrder = (int) $event['source_sequence'] < $maxSequence;
        }
        $lateWindow = max(0, (int) ($schema['late_window_seconds'] ?? DAY_IN_SECONDS));
        $isLate = ($recorded - $occurred) > $lateWindow || $outOfOrder;
        $propertiesJson = Json::canonical($processed['properties']);
        $payloadHash = hash('sha256', Json::canonical($event));
        $table = $this->db->table('events');
        $now = $this->db->now();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, min(365, (int) $schema['retention_days'])) * DAY_IN_SECONDS);

        $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (event_id,event_name,event_version,source_module,source_version,source_environment,source_sequence,occurred_at,recorded_at,actor_ref,object_ref,deletion_key,purpose,consent_version,policy_version,trace_id,properties_json,payload_hash,is_late,correction_of_event_id,expires_at,created_at) VALUES (%s,%s,%s,%s,%s,%s,NULLIF(%d,0),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)",
            (string) $event['event_id'],
            (string) $event['event_name'],
            (string) $event['event_version'],
            (string) $event['source_module'],
            (string) $event['source_version'],
            (string) $event['source_environment'],
            isset($event['source_sequence']) ? (int) $event['source_sequence'] : 0,
            gmdate('Y-m-d H:i:s', $occurred),
            gmdate('Y-m-d H:i:s', $recorded),
            $processed['actor_ref'],
            $processed['object_ref'],
            $processed['deletion_key'],
            (string) $event['purpose'],
            isset($event['consent_version']) ? (string) $event['consent_version'] : null,
            isset($event['policy_version']) ? (string) $event['policy_version'] : null,
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            $propertiesJson,
            $payloadHash,
            $isLate ? 1 : 0,
            isset($event['correction_of_event_id']) ? (string) $event['correction_of_event_id'] : null,
            $expiresAt,
            $now
        ));
        if ($inserted === false) {
            return new WP_Error('smai_event_store_failed', 'Event could not be stored.', ['status' => 500]);
        }
        if ($inserted === 0) {
            $existingHash = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                "SELECT payload_hash FROM `{$table}` WHERE event_id=%s",
                (string) $event['event_id']
            ));
            if (!is_string($existingHash) || !hash_equals($existingHash, $payloadHash)) {
                return $this->quarantine($event, 'event_id_collision', 'The event ID already exists with different content.', $service, 409);
            }
        }
        $status = $inserted === 0 ? 'duplicate_ignored' : 'accepted';
        $job = null;
        if ($inserted === 1) {
            $job = (new JobQueue($this->db))->enqueue('pipeline.process_event', [
                'event_id' => (string) $event['event_id'],
            ], 'pipeline|' . (string) $event['event_id']);
            if (is_wp_error($job)) {
                $status = 'accepted_pipeline_pending';
            }
        }

        $this->audit->log('analytics_event_ingested', 'analytics_event', (string) $event['event_id'], $status, [
            'event_name' => $event['event_name'],
            'event_version' => $event['event_version'],
            'source_module' => $event['source_module'],
            'source_version' => $event['source_version'],
            'service' => $service,
            'payload_hash' => $payloadHash,
            'is_late' => $isLate,
        ], (string) $event['purpose'], isset($event['trace_id']) ? (string) $event['trace_id'] : null, null, 'service');

        return [
            'event_id' => (string) $event['event_id'],
            'status' => $status,
            'payload_hash' => $payloadHash,
            'late' => $isLate,
            'out_of_order' => $outOfOrder,
            'pipeline_job' => is_wp_error($job) ? ['status' => 'queue_failed'] : $job,
        ];
    }

    /** @param array<string,mixed> $event */
    private function quarantine(array $event, string $code, string $detail, string $service, int $status): WP_Error
    {
        $table = $this->db->table('quarantine');
        $now = $this->db->now();
        $sample = [
            'keys' => array_slice(array_keys($event), 0, 30),
            'property_keys' => array_slice(array_keys(is_array($event['properties'] ?? null) ? $event['properties'] : []), 0, 30),
        ];
        $hash = hash('sha256', Json::canonical($event));
        $this->db->wpdb()->insert($table, [
            'event_id' => isset($event['event_id']) ? substr((string) $event['event_id'], 0, 64) : null,
            'event_name' => isset($event['event_name']) ? substr((string) $event['event_name'], 0, 190) : null,
            'event_version' => isset($event['event_version']) ? substr((string) $event['event_version'], 0, 32) : null,
            'source_module' => isset($event['source_module']) ? substr((string) $event['source_module'], 0, 100) : null,
            'reason_code' => substr($code, 0, 100),
            'reason_detail' => substr($detail, 0, 255),
            'redacted_sample' => Json::encode($sample),
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
