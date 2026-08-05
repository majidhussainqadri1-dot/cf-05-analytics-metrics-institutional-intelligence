<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;
use WP_REST_Request;

final class ServiceAuthenticator
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function authenticate(WP_REST_Request $request): string|WP_Error
    {
        if (!defined('SMAI_INGESTION_SECRET') || !is_string(SMAI_INGESTION_SECRET) || strlen(SMAI_INGESTION_SECRET) < 32) {
            return new WP_Error('smai_ingestion_secret_missing', 'Ingestion secret is not configured.', ['status' => 503]);
        }

        $service = sanitize_key((string) $request->get_header('x-sabri-service'));
        $timestamp = (string) $request->get_header('x-sabri-timestamp');
        $signature = strtolower((string) $request->get_header('x-sabri-signature'));
        if ($service === '' || $timestamp === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return new WP_Error('smai_service_auth_missing', 'Service authentication headers are incomplete.', ['status' => 401]);
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('smai_service_auth_expired', 'Service authentication timestamp is outside the allowed window.', ['status' => 401]);
        }

        $allowed = apply_filters('smai_allowed_ingestion_services', []);
        if (!is_array($allowed) || !in_array($service, array_map('sanitize_key', $allowed), true)) {
            return new WP_Error('smai_service_not_allowed', 'Service is not allowlisted.', ['status' => 403]);
        }

        $body = (string) $request->get_body();
        $material = strtoupper((string) $request->get_method()) . "\n" . $request->get_route() . "\n" . $timestamp . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $material, SMAI_INGESTION_SECRET);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('smai_service_signature_invalid', 'Service signature is invalid.', ['status' => 401]);
        }

        $nonceHash = hash('sha256', $service . '|' . $timestamp . '|' . $signature);
        $table = $this->db->table('ingestion_nonces');
        $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (nonce_hash,service,expires_at,created_at) VALUES (%s,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $nonceHash,
            $service,
            gmdate('Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS),
            gmdate('Y-m-d H:i:s')
        ));
        if ($inserted !== 1) {
            return new WP_Error('smai_service_replay', 'A replayed or unverifiable service request was rejected.', ['status' => 409]);
        }

        return $service;
    }
}
