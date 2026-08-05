<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class AuditLogger
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @param array<string,mixed> $context */
    public function log(string $action, string $objectType, ?string $objectRef, string $result, array $context = [], ?string $purpose = null, ?string $traceId = null, ?int $actorUserId = null, string $actorType = 'user'): bool
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('audit_log');
        $stateTable = $this->db->table('audit_state');
        $wpdb->query('START TRANSACTION');

        try {
            $head = $wpdb->get_row("SELECT last_hash,row_version FROM `{$stateTable}` WHERE id=1 FOR UPDATE", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (!is_array($head)) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $previous = (string) $head['last_hash'];
            if (!preg_match('/^[a-f0-9]{64}$/', $previous)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $eventUuid = Uuid::v4();
            $createdAt = gmdate('Y-m-d H:i:s');
            $safeContext = $this->minimize($context);
            $json = wp_json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $material = implode('|', [$eventUuid, (string) $actorUserId, $actorType, $action, $objectType, (string) $objectRef, (string) $purpose, $result, (string) $traceId, (string) $json, $previous, $createdAt]);
            $recordHash = hash('sha256', $material);

            $inserted = $wpdb->insert($table, [
                'event_uuid' => $eventUuid,
                'actor_user_id' => $actorUserId,
                'actor_type' => $actorType,
                'action' => substr($action, 0, 100),
                'object_type' => substr($objectType, 0, 100),
                'object_ref' => $objectRef ? substr($objectRef, 0, 190) : null,
                'purpose' => $purpose ? substr($purpose, 0, 190) : null,
                'result' => substr($result, 0, 32),
                'trace_id' => $traceId ? substr($traceId, 0, 100) : null,
                'context_json' => $json,
                'previous_hash' => $previous,
                'record_hash' => $recordHash,
                'created_at' => $createdAt,
            ]);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $updated = $wpdb->update($stateTable, [
                'last_hash' => $recordHash,
                'row_version' => (int) $head['row_version'] + 1,
                'updated_at' => $createdAt,
            ], ['id' => 1, 'last_hash' => $previous, 'row_version' => (int) $head['row_version']]);
            if ($updated !== 1) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function minimize(array $context): array
    {
        $blocked = ['password','otp','token','secret','cvv','pan','card','clinical','prescription','message_body','identity_document','raw_query'];
        $detector = new SensitiveValueDetector();
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $fragment) {
                if (str_contains($lower, $fragment)) {
                    continue 2;
                }
            }
            if ($detector->violations($value) !== []) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = is_string($value) ? Text::truncate($value, 500) : $value;
            } elseif (is_array($value)) {
                $out[(string) $key] = array_slice($value, 0, 20, true);
            }
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }
}
