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
    public function verify(int $limit = 100000): array
    {
        $table = $this->db->table('audit_log');
        $rows = $this->db->wpdb()->get_results(
            "SELECT * FROM `{$table}` ORDER BY id ASC LIMIT " . max(1, min(1000000, $limit)),
            ARRAY_A
        );
        $previous = str_repeat('0', 64);
        $checked = 0;
        $failures = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $checked++;
            if (!hash_equals($previous, (string) $row['previous_hash'])) {
                $failures[] = ['id' => (int) $row['id'], 'code' => 'previous_hash_mismatch'];
                break;
            }
            $material = implode('|', [
                $row['event_uuid'],
                (string) $row['actor_user_id'],
                $row['actor_type'],
                $row['action'],
                $row['object_type'],
                (string) $row['object_ref'],
                (string) $row['purpose'],
                $row['result'],
                (string) $row['trace_id'],
                (string) $row['context_json'],
                $row['previous_hash'],
                $row['created_at'],
            ]);
            $expected = hash('sha256', $material);
            if (!hash_equals($expected, (string) $row['record_hash'])) {
                $failures[] = ['id' => (int) $row['id'], 'code' => 'record_hash_mismatch'];
                break;
            }
            $previous = (string) $row['record_hash'];
        }
        $head = $this->db->wpdb()->get_var("SELECT last_hash FROM `{$this->db->table('audit_state')}` WHERE id=1");
        if ($failures === [] && is_string($head) && !hash_equals($previous, $head)) {
            $failures[] = ['id' => null, 'code' => 'head_hash_mismatch'];
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
