<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
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

    public function transition(string $objectType, int $objectId, string $targetState, int $expectedRowVersion, string $reason, int $actorUserId): array|WP_Error
    {
        if (!in_array($objectType, ['event_schema', 'metric'], true)) {
            return new WP_Error('smai_invalid_catalog_object', 'Unknown catalog object type.', ['status' => 400]);
        }
        if ($objectId < 1 || $expectedRowVersion < 1 || $actorUserId < 1) {
            return new WP_Error('smai_invalid_transition_context', 'Transition context is incomplete.', ['status' => 400]);
        }
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if (strlen($reason) < 8) {
            return new WP_Error('smai_transition_reason_required', 'A meaningful transition reason is required.', ['status' => 400]);
        }

        $wpdb = $this->db->wpdb();
        $table = $this->db->table($objectType === 'event_schema' ? 'event_schemas' : 'metrics');
        $historyTable = $this->db->table('governance_transitions');
        $wpdb->query('START TRANSACTION');

        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id,state,row_version,created_by FROM `{$table}` WHERE id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $objectId
            ), ARRAY_A);
            if (!is_array($row)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_catalog_object_not_found', 'Catalog object was not found.', ['status' => 404]);
            }

            $from = (string) $row['state'];
            $actualRowVersion = (int) $row['row_version'];
            if ($actualRowVersion !== $expectedRowVersion) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_stale_catalog_transition', 'Catalog object changed; refresh before retrying.', ['status' => 409, 'current_row_version' => $actualRowVersion]);
            }
            if (!LifecyclePolicy::allowed($objectType, $from, $targetState)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_invalid_catalog_transition', 'The requested lifecycle transition is not allowed.', ['status' => 409, 'from' => $from, 'to' => $targetState]);
            }
            if (LifecyclePolicy::requiresIndependentActor($objectType, $targetState) && (int) $row['created_by'] === $actorUserId) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_separation_of_duties', 'The creator cannot independently perform this approval transition.', ['status' => 403]);
            }

            $now = gmdate('Y-m-d H:i:s');
            $newVersion = $actualRowVersion + 1;
            $update = ['state' => $targetState, 'row_version' => $newVersion, 'updated_at' => $now];
            if ($objectType === 'metric' && $targetState === 'approved') {
                $update['approved_by'] = $actorUserId;
            }
            if ($objectType === 'metric' && $targetState === 'active') {
                $update['effective_from'] = $now;
                $update['effective_to'] = null;
            }
            if ($objectType === 'metric' && in_array($targetState, ['deprecated','revised','invalidated','retired'], true)) {
                $update['effective_to'] = $now;
            }

            $updated = $wpdb->update($table, $update, ['id' => $objectId, 'state' => $from, 'row_version' => $actualRowVersion]);
            if ($updated !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_catalog_transition_conflict', 'Catalog object changed concurrently.', ['status' => 409]);
            }

            $inserted = $wpdb->insert($historyTable, [
                'object_type' => $objectType,
                'object_id' => $objectId,
                'from_state' => $from,
                'to_state' => $targetState,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'row_version_from' => $actualRowVersion,
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
                'row_version_from' => $actualRowVersion,
                'row_version_to' => $newVersion,
            ], 'analytics_governance', null, $actorUserId);

            return ['id' => $objectId, 'object_type' => $objectType, 'from_state' => $from, 'state' => $targetState, 'row_version' => $newVersion];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_catalog_transition_failed', 'Catalog lifecycle transition failed safely.', ['status' => 500]);
        }
    }
}
