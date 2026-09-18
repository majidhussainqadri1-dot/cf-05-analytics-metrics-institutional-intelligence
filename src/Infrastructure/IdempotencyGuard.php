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
        if (!$this->validIdentity($scope, $actorRef, $key)
            || preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1) {
            return new WP_Error('smai_idempotency_key_required', 'Valid idempotency scope, actor, key and request hash are required.', ['status' => 400]);
        }

        $hash = $this->identityHash($scope, $actorRef, $key);
        $table = $this->db->table('idempotency_keys');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now = $this->db->now();
            $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
            $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
                "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,state,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,'started',%s,%s,%s)",
                $hash,
                $scope,
                $actorRef,
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
                "SELECT scope,actor_ref,request_hash,response_json,status_code,state,expires_at FROM `{$table}` WHERE idempotency_key=%s",
                $hash
            ), ARRAY_A);
            if (!is_array($row)
                || !hash_equals((string) $row['scope'], $scope)
                || !hash_equals((string) $row['actor_ref'], $actorRef)) {
                return new WP_Error('smai_idempotency_conflict', 'Idempotency identity conflicted with stored evidence.', ['status' => 409]);
            }

            if (strtotime((string) $row['expires_at']) <= time()) {
                $deleted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
                    "DELETE FROM `{$table}` WHERE idempotency_key=%s AND scope=%s AND actor_ref=%s AND expires_at=%s",
                    $hash,
                    $scope,
                    $actorRef,
                    (string) $row['expires_at']
                ));
                if ($deleted === false) {
                    return new WP_Error('smai_idempotency_store_failed', 'Expired idempotency evidence could not be recycled safely.', ['status' => 500]);
                }
                if ($deleted === 1 && $attempt === 0) {
                    continue;
                }
                return new WP_Error('smai_idempotency_in_progress', 'Idempotency evidence changed concurrently; retry the request.', ['status' => 409]);
            }

            if (!hash_equals((string) $row['request_hash'], $requestHash)) {
                return new WP_Error('smai_idempotency_conflict', 'Idempotency key was reused with a different request.', ['status' => 409]);
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

        return new WP_Error('smai_idempotency_store_failed', 'Idempotency state could not be established.', ['status' => 500]);
    }

    public function release(string $scope, string $actorRef, string $key): bool
    {
        if (!$this->validIdentity($scope, $actorRef, $key)) {
            return false;
        }
        $hash = $this->identityHash($scope, $actorRef, $key);
        return $this->db->wpdb()->delete(
            $this->db->table('idempotency_keys'),
            ['idempotency_key' => $hash, 'scope' => $scope, 'actor_ref' => $actorRef, 'state' => 'started']
        ) === 1;
    }

    /** @param array<string,mixed> $response */
    public function finish(string $scope, string $actorRef, string $key, array $response, int $statusCode): bool
    {
        if (!$this->validIdentity($scope, $actorRef, $key) || $statusCode < 100 || $statusCode > 599) {
            return false;
        }
        $hash = $this->identityHash($scope, $actorRef, $key);
        return $this->db->wpdb()->update($this->db->table('idempotency_keys'), [
            'state' => 'completed',
            'response_json' => Json::encode($response),
            'status_code' => $statusCode,
            'updated_at' => $this->db->now(),
        ], [
            'idempotency_key' => $hash,
            'scope' => $scope,
            'actor_ref' => $actorRef,
            'state' => 'started',
        ]) === 1;
    }

    private function validIdentity(string $scope, string $actorRef, string $key): bool
    {
        return preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $scope) === 1
            && preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $actorRef) === 1
            && strlen($key) >= 8
            && strlen($key) <= 200;
    }

    private function identityHash(string $scope, string $actorRef, string $key): string
    {
        return hash('sha256', $scope . '|' . $actorRef . '|' . $key);
    }
}
