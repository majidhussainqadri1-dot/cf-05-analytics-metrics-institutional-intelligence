<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;

final class CatalogLifecycleService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    public function transition(string $objectType, int $objectId, string $targetState, int $expectedRowVersion, string $reason, int $actorUserId, ?string $idempotencyKey = null): array|WP_Error
    {
        $tableName = match ($objectType) {
            'event_schema' => 'event_schemas',
            'metric' => 'metrics',
            'dataset' => 'datasets',
            default => null,
        };
        if ($tableName === null) {
            return new WP_Error('smai_invalid_catalog_object', 'Unknown catalog object type.', ['status' => 400]);
        }
        if ($objectId < 1 || $expectedRowVersion < 1 || $actorUserId < 1) {
            return new WP_Error('smai_invalid_transition_context', 'Transition context is incomplete.', ['status' => 400]);
        }
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if (strlen($reason) < 8) {
            return new WP_Error('smai_transition_reason_required', 'A meaningful transition reason is required.', ['status' => 400]);
        }
        $idempotencyHash = $idempotencyKey ? hash('sha256', $objectType . '|' . $objectId . '|' . $idempotencyKey) : null;

        $wpdb = $this->db->wpdb();
        $table = $this->db->table($tableName);
        $history = $this->db->table('governance_transitions');
        if ($idempotencyHash !== null) {
            $prior = $wpdb->get_row($wpdb->prepare(
                "SELECT to_state,row_version_to FROM `{$history}` WHERE object_type=%s AND object_id=%d AND idempotency_key=%s",
                $objectType,
                $objectId,
                $idempotencyHash
            ), ARRAY_A);
            if (is_array($prior)) {
                return ['id' => $objectId, 'object_type' => $objectType, 'state' => $prior['to_state'], 'row_version' => (int) $prior['row_version_to'], 'duplicate' => true];
            }
        }

        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE id=%d FOR UPDATE",
                $objectId
            ), ARRAY_A);
            if (!is_array($row)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_catalog_object_not_found', 'Catalog object was not found.', ['status' => 404]);
            }
            $from = (string) $row['state'];
            $actualVersion = (int) $row['row_version'];
            if ($actualVersion !== $expectedRowVersion) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_stale_catalog_transition', 'Catalog object changed; refresh before retrying.', ['status' => 409, 'current_row_version' => $actualVersion]);
            }
            if (!LifecyclePolicy::allowed($objectType, $from, $targetState)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_invalid_catalog_transition', 'The requested lifecycle transition is not allowed.', ['status' => 409, 'from' => $from, 'to' => $targetState]);
            }
            if (LifecyclePolicy::requiresIndependentActor($objectType, $targetState) && (int) $row['created_by'] === $actorUserId) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_separation_of_duties', 'The creator cannot independently perform this approval transition.', ['status' => 403]);
            }
            $readiness = $this->readinessErrors($objectType, $targetState, $row);
            if ($readiness !== []) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_catalog_not_ready', 'Catalog object is not ready for this transition.', ['status' => 409, 'errors' => $readiness]);
            }

            $now = $this->db->now();
            $newVersion = $actualVersion + 1;
            $update = ['state' => $targetState, 'row_version' => $newVersion, 'updated_at' => $now];
            if (in_array($objectType, ['metric','dataset'], true) && in_array($targetState, ['approved','privacy_approved'], true)) {
                $update['approved_by'] = $actorUserId;
            }
            if ($objectType === 'metric' && $targetState === 'active') {
                $update['effective_from'] = $now;
                $update['effective_to'] = null;
            }
            if ($objectType === 'metric' && in_array($targetState, ['deprecated','revised','invalidated','retired'], true)) {
                $update['effective_to'] = $now;
            }
            $updated = $wpdb->update($table, $update, ['id' => $objectId, 'state' => $from, 'row_version' => $actualVersion]);
            if ($updated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_catalog_transition_conflict', 'Catalog object changed concurrently.', ['status' => 409]);
            }
            $inserted = $wpdb->insert($history, [
                'object_type' => $objectType,
                'object_id' => $objectId,
                'from_state' => $from,
                'to_state' => $targetState,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'idempotency_key' => $idempotencyHash,
                'policy_version' => SMAI_CONTRACT_VERSION,
                'trace_id' => null,
                'row_version_from' => $actualVersion,
                'row_version_to' => $newVersion,
                'created_at' => $now,
            ]);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_transition_history_failed', 'Transition history could not be stored.', ['status' => 500]);
            }
            $wpdb->query('COMMIT');
            $this->audit->log('catalog_lifecycle_transition', $objectType, (string) $objectId, 'success', [
                'from_state' => $from,
                'to_state' => $targetState,
                'row_version_from' => $actualVersion,
                'row_version_to' => $newVersion,
            ], 'analytics_governance', null, $actorUserId);
            return ['id' => $objectId, 'object_type' => $objectType, 'from_state' => $from, 'state' => $targetState, 'row_version' => $newVersion, 'duplicate' => false];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_catalog_transition_failed', 'Catalog lifecycle transition failed safely.', ['status' => 500]);
        }
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function readinessErrors(string $objectType, string $targetState, array $row): array
    {
        $errors = [];
        if ($objectType === 'dataset') {
            $datasetId = (string) $row['dataset_id'];
            $version = (string) $row['dataset_version'];
            if (in_array($targetState, ['built','quality_validated','privacy_approved','published'], true)) {
                $build = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                    "SELECT state,row_count FROM `{$this->db->table('dataset_builds')}` WHERE dataset_id=%s AND dataset_version=%s ORDER BY id DESC LIMIT 1",
                    $datasetId,
                    $version
                ), ARRAY_A);
                if (!is_array($build) || !in_array((string) $build['state'], ['compared','active'], true)) {
                    $errors[] = 'dataset_build_missing_or_uncompared';
                }
            }
            if (in_array($targetState, ['quality_validated','privacy_approved','published'], true) && (string) $row['quality_status'] !== 'green') {
                $errors[] = 'dataset_quality_not_green';
            }
            if ($targetState === 'published') {
                $provider = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                    "SELECT COUNT(*) FROM `{$this->db->table('providers')}` WHERE provider_id=%s AND state='active'",
                    $row['provider_id']
                ));
                if ((int) $provider < 1 && (string) $row['provider_id'] !== 'local') {
                    $errors[] = 'provider_not_active';
                }
            }
        }
        if ($objectType === 'metric' && in_array($targetState, ['validated','approved','active'], true)) {
            $definition = Json::object((string) $row['definition_json']);
            $source = is_array($definition['source'] ?? null) ? $definition['source'] : [];
            if (empty($source['dataset_id']) || empty($source['dataset_version']) || !is_array($definition['calculation'] ?? null)) {
                $errors[] = 'metric_source_or_calculation_missing';
            } elseif ($targetState === 'active') {
                $dataset = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                    "SELECT COUNT(*) FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s AND state='published'",
                    $source['dataset_id'],
                    $source['dataset_version']
                ));
                if ((int) $dataset < 1) {
                    $errors[] = 'metric_source_dataset_not_published';
                }
            }
        }
        return $errors;
    }
}
