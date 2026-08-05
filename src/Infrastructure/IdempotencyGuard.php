<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class IdempotencyGuard
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array{state:string,response:mixed,status_code:?int}|WP_Error */
    public function begin(string $scope, string $actorRef, string $key, string $requestHash): array|WP_Error
    {
        if (strlen($key) < 8 || strlen($key) > 200) {
            return new WP_Error('smai_idempotency_key_required', 'A valid Idempotency-Key header is required.', ['status' => 400]);
        }
        $hash = hash('sha256', $scope . '|' . $actorRef . '|' . $key);
        $table = $this->db->table('idempotency_keys');
        $now = $this->db->now();
        $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,state,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,'started',%s,%s,%s)",
            $hash,
            Text::truncate($scope, 100),
            Text::truncate($actorRef, 100),
            $requestHash,
            $expires,
            $now,
            $now
        ));
        if ($inserted === false) {
            return new WP_Error('smai_idempotency_store_failed', 'Idempotency state could not be stored.', ['status' => 500]);
        }
        if ($inserted === 1) {
            return ['state' => 'started', 'response' => null, 'status_code' => null];
        }
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT request_hash,response_json,status_code,state,expires_at FROM `{$table}` WHERE idempotency_key=%s",
            $hash
        ), ARRAY_A);
        if (!is_array($row) || !hash_equals((string) $row['request_hash'], $requestHash)) {
            return new WP_Error('smai_idempotency_conflict', 'Idempotency key was reused with a different request.', ['status' => 409]);
        }
        if (strtotime((string) $row['expires_at']) <= time()) {
            return new WP_Error('smai_idempotency_expired', 'Idempotency key has expired.', ['status' => 409]);
        }
        if ((string) $row['state'] === 'completed') {
            return [
                'state' => 'completed',
                'response' => Json::object((string) ($row['response_json'] ?? '{}')),
                'status_code' => (int) ($row['status_code'] ?? 200),
            ];
        }
        return new WP_Error('smai_idempotency_in_progress', 'An identical request is already in progress.', ['status' => 409]);
    }

    public function release(string $scope, string $actorRef, string $key): bool
    {
        $hash = hash('sha256', $scope . '|' . $actorRef . '|' . $key);
        return $this->db->wpdb()->delete(
            $this->db->table('idempotency_keys'),
            ['idempotency_key' => $hash, 'state' => 'started']
        ) === 1;
    }

    /** @param array<string,mixed> $response */
    public function finish(string $scope, string $actorRef, string $key, array $response, int $statusCode): bool
    {
        $hash = hash('sha256', $scope . '|' . $actorRef . '|' . $key);
        return $this->db->wpdb()->update($this->db->table('idempotency_keys'), [
            'state' => 'completed',
            'response_json' => Json::encode($response),
            'status_code' => $statusCode,
            'updated_at' => $this->db->now(),
        ], ['idempotency_key' => $hash, 'state' => 'started']) === 1;
    }
}
