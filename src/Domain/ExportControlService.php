<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
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
        if ($actorUserId < 1 || strlen($reason) < 8) {
            return new WP_Error('smai_export_revoke_reason_required', 'A meaningful revocation reason is required.', ['status' => 400]);
        }
        $table = $this->db->table('exports');
        $export = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE export_uuid=%s",
            $uuid
        ), ARRAY_A);
        if (!is_array($export)) {
            return new WP_Error('smai_export_unavailable', 'Export is unavailable.', ['status' => 404]);
        }
        $authorized = (int) $export['requester_user_id'] === $actorUserId
            || user_can($actorUserId, 'smai_manage_access')
            || user_can($actorUserId, 'smai_audit');
        if (!$authorized) {
            return new WP_Error('smai_export_revoke_denied', 'You are not authorized to revoke this export.', ['status' => 403]);
        }
        if ((string) $export['state'] === 'revoked' || $export['revoked_at'] !== null) {
            return ['export_uuid' => $uuid, 'state' => 'revoked', 'unchanged' => true];
        }
        $now = $this->db->now();
        $updated = $this->db->wpdb()->update($table, [
            'state' => 'revoked',
            'revoked_at' => $now,
            'expires_at' => $now,
            'token_hash' => null,
            'updated_at' => $now,
        ], [
            'id' => (int) $export['id'],
            'state' => (string) $export['state'],
        ]);
        if ($updated !== 1) {
            return new WP_Error('smai_export_revoke_conflict', 'Export changed concurrently.', ['status' => 409]);
        }
        $this->db->wpdb()->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid], ['%s']);
        $this->audit->log('analytics_export_revoked', 'export', $uuid, 'success', [
            'previous_state' => (string) $export['state'],
            'reason' => $reason,
            'project_uuid' => (string) $export['project_uuid'],
        ], (string) $export['purpose'], null, $actorUserId);
        return ['export_uuid' => $uuid, 'state' => 'revoked', 'revoked_at' => gmdate('c', (int) strtotime($now)), 'unchanged' => false];
    }
}
