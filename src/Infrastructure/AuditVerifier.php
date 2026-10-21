<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class AuditVerifier
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function verify(int $pageSize = 10000): array
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('audit_log');
        $stateTable = $this->db->table('audit_state');
        $pageSize = max(100, min(50000, $pageSize));
        $previous = str_repeat('0', 64);
        $checked = 0;
        $lastId = 0;
        $failures = [];

        if ($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            return [
                'status' => 'failed',
                'checked_records' => 0,
                'last_hash' => $previous,
                'failures' => [['id' => null, 'code' => 'audit_snapshot_failed']],
                'verified_at' => gmdate('c'),
            ];
        }

        try {
            $head = $wpdb->get_row("SELECT last_hash,row_version FROM `{$stateTable}` WHERE id=1", ARRAY_A);
            if (!is_array($head)
                || preg_match('/^[a-f0-9]{64}$/', (string) ($head['last_hash'] ?? '')) !== 1
                || (int) ($head['row_version'] ?? 0) < 1) {
                $failures[] = ['id' => null, 'code' => 'audit_state_missing_or_invalid'];
            }

            while ($failures === []) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE id>%d ORDER BY id ASC LIMIT %d",
                    $lastId,
                    $pageSize
                ), ARRAY_A);
                if (!is_array($rows)) {
                    $failures[] = ['id' => null, 'code' => 'audit_query_failed'];
                    break;
                }
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $checked++;
                    $lastId = (int) $row['id'];

                    if (!hash_equals($previous, (string) $row['previous_hash'])) {
                        $failures[] = ['id' => $lastId, 'code' => 'previous_hash_mismatch'];
                        break 2;
                    }

                    $context = json_decode((string) ($row['context_json'] ?? ''), true);
                    if (!is_array($context) || json_last_error() !== JSON_ERROR_NONE) {
                        $failures[] = ['id' => $lastId, 'code' => 'context_json_invalid'];
                        break 2;
                    }

                    $material = Json::canonical([
                        'event_uuid' => (string) $row['event_uuid'],
                        'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                        'actor_type' => (string) $row['actor_type'],
                        'action' => (string) $row['action'],
                        'object_type' => (string) $row['object_type'],
                        'object_ref' => $row['object_ref'] === null ? null : (string) $row['object_ref'],
                        'purpose' => $row['purpose'] === null ? null : (string) $row['purpose'],
                        'result' => (string) $row['result'],
                        'trace_id' => $row['trace_id'] === null ? null : (string) $row['trace_id'],
                        'context' => $context,
                        'previous_hash' => (string) $row['previous_hash'],
                        'created_at' => (string) $row['created_at'],
                    ]);
                    $expected = hash('sha256', $material);
                    if (!hash_equals($expected, (string) $row['record_hash'])) {
                        $failures[] = ['id' => $lastId, 'code' => 'record_hash_mismatch'];
                        break 2;
                    }
                    $previous = (string) $row['record_hash'];
                }

                if (count($rows) < $pageSize) {
                    break;
                }
            }

            if ($failures === [] && is_array($head)) {
                if (!hash_equals($previous, (string) $head['last_hash'])) {
                    $failures[] = ['id' => null, 'code' => 'head_hash_mismatch'];
                } elseif ((int) $head['row_version'] !== $checked + 1) {
                    $failures[] = ['id' => null, 'code' => 'audit_state_row_version_mismatch'];
                }
            }

            if ($wpdb->query('COMMIT') === false) {
                $failures[] = ['id' => null, 'code' => 'audit_snapshot_commit_failed'];
                $wpdb->query('ROLLBACK');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $failures[] = ['id' => null, 'code' => 'audit_verification_exception'];
        }

        return [
            'status' => $failures === [] ? 'verified' : 'failed',
            'checked_records' => $checked,
            'last_hash' => $previous,
            'failures' => $failures,
            'verified_at' => gmdate('c'),
        ];
    }
}
