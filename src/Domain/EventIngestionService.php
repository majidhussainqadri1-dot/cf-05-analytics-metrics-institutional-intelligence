<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use WP_Error;

final class EventIngestionService
{
    private const ENVELOPE_KEYS = [
        'event_id','event_name','event_version','source_module','source_version','source_environment',
        'source_sequence','occurred_at','recorded_at','actor_ref','object_ref','deletion_key','purpose',
        'consent_granted','consent_version','guardian_consent_verified','guardian_consent_version',
        'policy_version','is_minor','region_code','provider_id','trace_id','correction_of_event_id','properties',
    ];

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
        foreach (['event_id','event_name','event_version','source_module','source_version','source_environment','occurred_at','recorded_at','purpose','properties'] as $required) {
            if (!array_key_exists($required, $event)) {
                return $this->quarantine($event, 'missing_envelope_field', $required, $service, 400);
            }
        }
        foreach (array_keys($event) as $key) {
            if (!is_string($key) || !in_array($key, self::ENVELOPE_KEYS, true)) {
                return $this->quarantine($event, 'unknown_envelope_field', is_string($key) ? $key : 'non_string_key', $service, 400);
            }
        }

        $eventId = strtolower((string) $event['event_id']);
        if (!$this->isUuid4($eventId)) {
            return $this->quarantine($event, 'invalid_event_id', 'event_id', $service, 400);
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $event['event_name']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $event['event_version']) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $event['purpose']) !== 1) {
            return $this->quarantine($event, 'invalid_event_contract_identity', 'event_name, event_version or purpose', $service, 400);
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) $event['source_module']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', (string) $event['source_version']) !== 1
            || !in_array((string) $event['source_environment'], ['production','staging','development','local'], true)) {
            return $this->quarantine($event, 'invalid_source_identity', 'source identity', $service, 400);
        }
        $authorizedSource = (string) apply_filters('smai_service_source_module', $service, $service);
        if (!hash_equals($authorizedSource, (string) $event['source_module'])) {
            return $this->quarantine($event, 'service_source_mismatch', 'Service cannot emit for this source module.', $service, 403);
        }
        $actualEnvironment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';
        $allowedEnvironments = apply_filters('smai_allowed_source_environments', [$actualEnvironment], $service, (string) $event['source_module']);
        if (!is_array($allowedEnvironments) || !in_array((string) $event['source_environment'], array_map('strval', $allowedEnvironments), true)) {
            return $this->quarantine($event, 'source_environment_mismatch', 'Source environment is not valid for this runtime.', $service, 403);
        }

        $occurred = $this->strictTimestamp((string) $event['occurred_at']);
        $recorded = $this->strictTimestamp((string) $event['recorded_at']);
        if ($occurred === null || $recorded === null) {
            return $this->quarantine($event, 'invalid_event_time', 'occurred_at or recorded_at', $service, 400);
        }
        if ($occurred > time() + 300 || $recorded > time() + 300) {
            return $this->quarantine($event, 'future_event_time', 'Event time exceeds the allowed clock-skew window.', $service, 400);
        }
        if ($recorded + 300 < $occurred) {
            return $this->quarantine($event, 'invalid_recording_order', 'recorded_at cannot materially precede occurred_at.', $service, 400);
        }
        if (!is_array($event['properties']) || count($event['properties']) > 100) {
            return $this->quarantine($event, 'invalid_properties', 'properties must be a bounded object.', $service, 400);
        }
        if (isset($event['source_sequence']) && (!is_int($event['source_sequence']) || $event['source_sequence'] < 1)) {
            return $this->quarantine($event, 'invalid_source_sequence', 'source_sequence', $service, 400);
        }
        foreach (['consent_granted','guardian_consent_verified','is_minor'] as $booleanField) {
            if (isset($event[$booleanField]) && !is_bool($event[$booleanField])) {
                return $this->quarantine($event, 'invalid_envelope_metadata', $booleanField, $service, 400);
            }
        }
        foreach (['actor_ref','object_ref','deletion_key'] as $referenceField) {
            if (!array_key_exists($referenceField, $event) || $event[$referenceField] === null || $event[$referenceField] === '') { continue; }
            $reference = $event[$referenceField];
            if ((!is_string($reference) && !is_int($reference)) || (is_string($reference) && strlen($reference) > 190)) {
                return $this->quarantine($event, 'invalid_envelope_metadata', $referenceField, $service, 400);
            }
        }
        foreach (['consent_version' => 64, 'guardian_consent_version' => 64, 'policy_version' => 64, 'trace_id' => 100] as $field => $maximum) {
            if (isset($event[$field]) && (!is_string($event[$field]) || preg_match('/^[A-Za-z0-9_.:-]{1,' . $maximum . '}$/', $event[$field]) !== 1)) {
                return $this->quarantine($event, 'invalid_envelope_metadata', $field, $service, 400);
            }
        }
        if (isset($event['region_code']) && (!is_string($event['region_code']) || preg_match('/^[A-Z0-9-]{2,32}$/', $event['region_code']) !== 1)) {
            return $this->quarantine($event, 'invalid_envelope_metadata', 'region_code', $service, 400);
        }
        if (isset($event['provider_id']) && (!is_string($event['provider_id']) || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $event['provider_id']) !== 1)) {
            return $this->quarantine($event, 'invalid_envelope_metadata', 'provider_id', $service, 400);
        }
        if (isset($event['correction_of_event_id'])) {
            $correctionId = strtolower((string) $event['correction_of_event_id']);
            if (!$this->isUuid4($correctionId) || hash_equals($eventId, $correctionId)) {
                return $this->quarantine($event, 'invalid_correction_reference', 'correction_of_event_id', $service, 400);
            }
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
        $allowedRegions = is_array($schema['allowed_regions'] ?? null) ? array_map('strval', $schema['allowed_regions']) : [];
        if ($allowedRegions !== [] && !in_array((string) ($event['region_code'] ?? ''), $allowedRegions, true)) {
            return $this->quarantine($event, 'region_not_allowed', 'Event region is not approved.', $service, 403);
        }
        $allowedProviders = is_array($schema['allowed_providers'] ?? null) ? array_map('strval', $schema['allowed_providers']) : [];
        if ($allowedProviders !== [] && !in_array((string) ($event['provider_id'] ?? ''), $allowedProviders, true)) {
            return $this->quarantine($event, 'provider_not_allowed', 'Event provider is not approved.', $service, 403);
        }
        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_pseudonym_key_missing', 'Pseudonymization key is not configured.', ['status' => 503]);
        }

        $processed = (new PrivacyGateway(SMAI_PSEUDONYM_KEY))->process($event, $schema);
        if (!$processed['accepted']) {
            return $this->quarantine($event, 'privacy_gateway_rejected', implode(',', $processed['errors']), $service, 422);
        }

        $retentionDays = max(1, min(365, (int) $schema['retention_days']));
        $expiresTimestamp = $recorded + $retentionDays * DAY_IN_SECONDS;
        if ($expiresTimestamp <= time()) {
            return $this->quarantine($event, 'event_retention_expired', 'Event is already outside its approved retention window.', $service, 410);
        }

        if (!empty($event['correction_of_event_id'])) {
            $corrected = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                "SELECT event_name,event_version,source_module,occurred_at FROM `{$this->db->table('events')}` WHERE event_id=%s",
                strtolower((string) $event['correction_of_event_id'])
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
        $sequenceKey = null;
        if (isset($event['source_sequence'])) {
            $sequenceKey = hash('sha256', implode('|', [(string) $event['source_module'], (string) $event['source_environment'], (string) $event['source_sequence']]));
            $maximum = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                "SELECT COALESCE(MAX(source_sequence),0) FROM `{$this->db->table('events')}` WHERE source_module=%s AND source_environment=%s",
                (string) $event['source_module'],
                (string) $event['source_environment']
            ));
            $outOfOrder = (int) $event['source_sequence'] < $maximum;
        }
        $lateWindow = max(0, min(90 * DAY_IN_SECONDS, (int) ($schema['late_window_seconds'] ?? DAY_IN_SECONDS)));
        $isLate = ($recorded - $occurred) > $lateWindow || $outOfOrder;

        try {
            $propertiesJson = Json::canonical($processed['properties']);
            $payloadHash = hash('sha256', Json::canonical($event));
        } catch (\Throwable) {
            return $this->quarantine($event, 'invalid_json_value', 'Event contains a value that cannot be canonicalized.', $service, 400);
        }

        $table = $this->db->table('events');
        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_event_transaction_failed', 'Event transaction could not start.', ['status' => 500]);
        }
        try {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (event_id,event_name,event_version,source_module,source_version,source_environment,source_sequence,source_sequence_key,occurred_at,recorded_at,actor_ref,object_ref,deletion_key,purpose,consent_version,guardian_consent_version,region_code,provider_id,policy_version,trace_id,properties_json,payload_hash,is_late,correction_of_event_id,expires_at,created_at) VALUES (%s,%s,%s,%s,%s,%s,NULLIF(%d,0),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)",
                $eventId,
                (string) $event['event_name'],
                (string) $event['event_version'],
                (string) $event['source_module'],
                (string) $event['source_version'],
                (string) $event['source_environment'],
                isset($event['source_sequence']) ? (int) $event['source_sequence'] : 0,
                $sequenceKey,
                gmdate('Y-m-d H:i:s', $occurred),
                gmdate('Y-m-d H:i:s', $recorded),
                $processed['actor_ref'],
                $processed['object_ref'],
                $processed['deletion_key'],
                (string) $event['purpose'],
                isset($event['consent_version']) ? (string) $event['consent_version'] : null,
                isset($event['guardian_consent_version']) ? (string) $event['guardian_consent_version'] : null,
                isset($event['region_code']) ? (string) $event['region_code'] : null,
                isset($event['provider_id']) ? (string) $event['provider_id'] : null,
                isset($event['policy_version']) ? (string) $event['policy_version'] : null,
                isset($event['trace_id']) ? (string) $event['trace_id'] : null,
                $propertiesJson,
                $payloadHash,
                $isLate ? 1 : 0,
                isset($event['correction_of_event_id']) ? strtolower((string) $event['correction_of_event_id']) : null,
                gmdate('Y-m-d H:i:s', $expiresTimestamp),
                $now
            ));
            if ($inserted === false) {
                throw new \RuntimeException('Event could not be stored.');
            }
            if ($inserted === 0) {
                $existing = $wpdb->get_row($wpdb->prepare("SELECT event_id,payload_hash FROM `{$table}` WHERE event_id=%s", $eventId), ARRAY_A);
                if (is_array($existing) && hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                    if ($wpdb->query('COMMIT') === false) {
                        $wpdb->query('ROLLBACK');
                        return new WP_Error('smai_event_commit_failed', 'Duplicate-event verification could not be committed.', ['status' => 500]);
                    }
                    return ['event_id' => $eventId, 'status' => 'duplicate_ignored', 'payload_hash' => $payloadHash, 'late' => $isLate, 'out_of_order' => $outOfOrder, 'pipeline_job' => null];
                }
                if ($sequenceKey !== null) {
                    $sequenceOwner = $wpdb->get_var($wpdb->prepare("SELECT event_id FROM `{$table}` WHERE source_sequence_key=%s", $sequenceKey));
                    if (is_string($sequenceOwner) && !hash_equals($sequenceOwner, $eventId)) {
                        $wpdb->query('ROLLBACK');
                        return $this->quarantine($event, 'source_sequence_collision', 'Source sequence is already bound to another event.', $service, 409);
                    }
                }
                $wpdb->query('ROLLBACK');
                return $this->quarantine($event, 'event_id_collision', 'The event ID already exists with different content.', $service, 409);
            }

            $job = (new JobQueue($this->db))->enqueue('pipeline.process_event', ['event_id' => $eventId], 'pipeline|' . $eventId);
            if (is_wp_error($job)) {
                throw new \RuntimeException('Pipeline job could not be recorded atomically.');
            }
            if (!$this->audit->logInOpenTransaction(
                'analytics_event_ingested', 'analytics_event', $eventId, 'accepted',
                ['event_name'=>$event['event_name'],'event_version'=>$event['event_version'],'source_module'=>$event['source_module'],'source_version'=>$event['source_version'],'service'=>$service,'payload_hash'=>$payloadHash,'is_late'=>$isLate],
                (string)$event['purpose'], isset($event['trace_id']) ? (string)$event['trace_id'] : null, null, 'service'
            )) {
                throw new \RuntimeException('Event audit evidence could not be recorded atomically.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Event transaction could not be committed.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_event_store_failed', 'Event, pipeline and audit evidence were not committed.', ['status' => 500]);
        }

        return [
            'event_id' => $eventId,
            'status' => 'accepted',
            'payload_hash' => $payloadHash,
            'late' => $isLate,
            'out_of_order' => $outOfOrder,
            'pipeline_job' => $job,
        ];
    }

    /** @param array<string,mixed> $event */
    private function quarantine(array $event, string $code, string $detail, string $service, int $status): WP_Error
    {
        try {
            $hash = hash('sha256', Json::canonical($event));
        } catch (\Throwable) {
            $hash = hash('sha256', serialize(array_keys($event)));
        }
        $now = $this->db->now();
        $sample = [
            'keys' => array_slice(array_map('strval', array_keys($event)), 0, 30),
            'property_keys' => array_slice(array_map('strval', array_keys(is_array($event['properties'] ?? null) ? $event['properties'] : [])), 0, 30),
        ];
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_quarantine_evidence_failed', 'Event was rejected but quarantine evidence transaction could not start.', ['status' => 503, 'reason_code' => $code]);
        }
        $stored = $wpdb->insert($this->db->table('quarantine'), [
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
        $logged = $stored === 1 && $this->audit->logInOpenTransaction(
            'analytics_event_quarantined',
            'quarantine',
            isset($event['event_id']) ? (string) $event['event_id'] : null,
            'rejected',
            ['reason_code' => $code, 'service' => $service, 'payload_hash' => $hash, 'quarantine_stored' => true],
            isset($event['purpose']) ? (string) $event['purpose'] : null,
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            null,
            'service'
        );
        if (!$logged || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_quarantine_evidence_failed', 'Event was rejected but quarantine evidence could not be fully persisted.', ['status' => 503, 'reason_code' => $code]);
        }
        return new WP_Error('smai_event_quarantined', 'Event was quarantined.', ['status' => $status, 'reason_code' => $code]);
    }

    private function isUuid4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }

    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}
