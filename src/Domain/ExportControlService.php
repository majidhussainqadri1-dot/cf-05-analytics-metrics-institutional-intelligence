<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;

final class ExportControlService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    public function revoke(string $uuid, string $reason, int $actorUserId): array|WP_Error
    {
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if ($actorUserId < 1 || preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1 || strlen($reason) < 8
            || (new SensitiveValueDetector())->violations($reason) !== []) {
            return new WP_Error('smai_export_revoke_reason_required', 'A meaningful and safe revocation reason is required.', ['status' => 400]);
        }
        $table = $this->db->table('exports');
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE export_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($export)) {
            return new WP_Error('smai_export_unavailable', 'Export is unavailable.', ['status' => 404]);
        }
        if ((int) $export['requester_user_id'] !== $actorUserId && !user_can($actorUserId, 'smai_manage_access')) {
            return new WP_Error('smai_export_revoke_denied', 'You are not authorized to revoke this export.', ['status' => 403]);
        }
        if ((string) $export['state'] === 'revoked' || $export['revoked_at'] !== null) {
            return ['export_uuid' => $uuid, 'state' => 'revoked', 'unchanged' => true];
        }
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_export_revoke_transaction_failed', 'Export revocation transaction could not start.', ['status' => 500]); }
        $now = $this->db->now();
        $updated = $wpdb->update($table, [
            'state' => 'revoked', 'revoked_at' => $now, 'expires_at' => $now, 'token_hash' => null, 'updated_at' => $now,
        ], ['id' => (int) $export['id'], 'state' => (string) $export['state'], 'revoked_at' => $export['revoked_at']]);
        $deleted = $wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid], ['%s']);
        if ($updated !== 1 || $deleted === false || !$this->audit->logInOpenTransaction('analytics_export_revoked', 'export', $uuid, 'success', [
            'previous_state' => (string) $export['state'], 'reason' => $reason, 'project_uuid' => (string) $export['project_uuid'],
        ], (string) $export['purpose'], null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_export_revoke_conflict', 'Export revocation and audit evidence could not be committed.', ['status' => 409]);
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_export_revoke_commit_failed', 'Export revocation could not be committed.', ['status' => 500]); }
        return ['export_uuid' => $uuid, 'state' => 'revoked', 'revoked_at' => gmdate('c', (int) strtotime($now)), 'unchanged' => false];
    }
}
