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
            $head = $wpdb->get_row("SELECT last_hash,row_version FROM `{$stateTable}` WHERE id=1 FOR UPDATE", ARRAY_A);
            if (!is_array($head) || preg_match('/^[a-f0-9]{64}$/', (string) $head['last_hash']) !== 1) {
                throw new \RuntimeException('Audit head unavailable.');
            }
            $previous = (string) $head['last_hash'];
            $eventUuid = Uuid::v4();
            $createdAt = $this->db->now();
            $safeContext = $this->minimize($context);
            $json = Json::canonical($safeContext);
            $material = Json::canonical([
                'event_uuid' => $eventUuid,
                'actor_user_id' => $actorUserId,
                'actor_type' => Text::truncate($actorType, 32),
                'action' => Text::truncate($action, 100),
                'object_type' => Text::truncate($objectType, 100),
                'object_ref' => $objectRef === null ? null : Text::truncate($objectRef, 190),
                'purpose' => $purpose === null ? null : Text::truncate($purpose, 190),
                'result' => Text::truncate($result, 32),
                'trace_id' => $traceId === null ? null : Text::truncate($traceId, 100),
                'context' => $safeContext,
                'previous_hash' => $previous,
                'created_at' => $createdAt,
            ]);
            $recordHash = hash('sha256', $material);
            if ($wpdb->insert($table, [
                'event_uuid' => $eventUuid,
                'actor_user_id' => $actorUserId,
                'actor_type' => Text::truncate($actorType, 32),
                'action' => Text::truncate($action, 100),
                'object_type' => Text::truncate($objectType, 100),
                'object_ref' => $objectRef === null ? null : Text::truncate($objectRef, 190),
                'purpose' => $purpose === null ? null : Text::truncate($purpose, 190),
                'result' => Text::truncate($result, 32),
                'trace_id' => $traceId === null ? null : Text::truncate($traceId, 100),
                'context_json' => $json,
                'previous_hash' => $previous,
                'record_hash' => $recordHash,
                'created_at' => $createdAt,
            ]) !== 1) {
                throw new \RuntimeException('Audit insert failed.');
            }
            if ($wpdb->update($stateTable, [
                'last_hash' => $recordHash,
                'row_version' => (int) $head['row_version'] + 1,
                'updated_at' => $createdAt,
            ], ['id' => 1, 'last_hash' => $previous, 'row_version' => (int) $head['row_version']]) !== 1) {
                throw new \RuntimeException('Audit head update failed.');
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
        $detector = new SensitiveValueDetector();
        $out = [];
        foreach (array_slice($context, 0, 40, true) as $key => $value) {
            $keyText = strtolower((string) $key);
            if (preg_match('/password|otp|token|secret|cvv|pan|card|clinical|prescription|message_body|identity_document|raw_query/', $keyText) === 1 || $detector->violations($value) !== []) {
                continue;
            }
            $out[(string) $key] = $this->safeValue($value, 0);
        }
        return $out;
    }

    private function safeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 3) {
            return '[truncated]';
        }
        if (is_string($value)) {
            return Text::truncate(wp_strip_all_tags($value), 500);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 20, true) as $key => $item) {
                $out[(string) $key] = $this->safeValue($item, $depth + 1);
            }
            return $out;
        }
        return '[unsupported]';
    }
}
