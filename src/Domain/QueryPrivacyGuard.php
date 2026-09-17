<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use WP_Error;

final class QueryPrivacyGuard
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $dimensions */
    public function evaluateAndRecord(
        string $metricId,
        string $metricVersion,
        string $projectUuid,
        int $actorUserId,
        string $purpose,
        string $windowStart,
        string $windowEnd,
        array $dimensions,
        array $definition,
        int $cohortSize,
        int $globalMinimum
    ): bool|WP_Error {
        $violations = PrivacyQueryPolicy::violations($definition, $dimensions);
        if ($violations !== []) {
            return $this->deny('smai_query_privacy_policy', 'Requested dimensions violate the metric privacy policy.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['violations' => $violations]);
        }

        $minimum = PrivacyQueryPolicy::effectiveMinimum($definition, $dimensions, $globalMinimum);
        if ($cohortSize < $minimum) {
            return $this->deny('smai_cohort_suppressed', 'Result is suppressed by a dimension-specific minimum cohort policy.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['minimum_cohort' => $minimum]);
        }

        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_query_privacy_key_missing', 'Query privacy protection is unavailable.', ['status' => 503]);
        }
        $privacyKey = SMAI_PSEUDONYM_KEY;
        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $differencingFloor = max($minimum, (int) ($controls['differencing_floor'] ?? $minimum));
        $maxSlices = max(5, min(200, (int) ($controls['max_distinct_slices_per_hour'] ?? 30)));
        $maxBudget = max(10, min(1000, (int) ($controls['max_privacy_budget_per_hour'] ?? 80)));
        $cost = PrivacyQueryPolicy::privacyCost($definition, $dimensions);

        ksort($dimensions);
        $dimensionHashes = [];
        foreach ($dimensions as $name => $value) {
            $dimensionHashes[(string) $name] = hash_hmac('sha256', Json::canonical($value), $privacyKey);
        }
        $current = [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'dimension_names' => array_values(array_map('strval', array_keys($dimensions))),
            'dimension_hashes' => $dimensionHashes,
            'dimensions_fingerprint' => hash_hmac('sha256', Json::canonical($dimensions), $privacyKey),
            'cohort_size' => $cohortSize,
            'privacy_cost' => $cost,
        ];

        $actorRef = hash_hmac('sha256', 'query|' . $actorUserId . '|' . $projectUuid . '|' . $metricId . '@' . $metricVersion, $privacyKey);
        $table = $this->db->table('idempotency_keys');
        $wpdb = $this->db->wpdb();
        $lockName = 'smai_privacy_' . substr(hash_hmac('sha256', $actorRef, $privacyKey), 0, 48);
        $lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lockName));
        if ($lock !== 1) {
            return new WP_Error('smai_privacy_lock_unavailable', 'Query privacy accounting is temporarily unavailable.', ['status' => 503]);
        }
        try {
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT request_hash,response_json,created_at FROM `{$table}` WHERE scope='privacy-query' AND actor_ref=%s AND state='completed' AND created_at>=%s ORDER BY created_at DESC LIMIT 200",
            $actorRef,
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ), ARRAY_A);

        $fingerprints = [];
        $budget = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $previous = Json::object((string) ($row['response_json'] ?? '{}'));
            $fingerprint = (string) ($previous['dimensions_fingerprint'] ?? '');
            if ($fingerprint !== '') {
                $fingerprints[$fingerprint] = true;
            }
            $budget += max(0, (int) ($previous['privacy_cost'] ?? 0));
            if (PrivacyQueryPolicy::differencingRisk($current, $previous, $differencingFloor)) {
                return $this->deny('smai_differencing_risk', 'Query blocked because it could isolate a small cohort by differencing recent slices.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['minimum_difference' => $differencingFloor]);
            }
        }

        $exactRepeated = isset($fingerprints[$current['dimensions_fingerprint']]);
        if (!$exactRepeated && count($fingerprints) >= $maxSlices) {
            return $this->deny('smai_slice_budget_exceeded', 'Too many distinct metric slices were requested in the current privacy window.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['max_distinct_slices' => $maxSlices]);
        }
        if (!$exactRepeated && $budget + $cost > $maxBudget) {
            return $this->deny('smai_privacy_budget_exceeded', 'The query privacy budget for this project and metric has been exhausted.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['max_privacy_budget' => $maxBudget]);
        }

        $requestHash = hash_hmac('sha256', Json::canonical([
            'metric_id' => $metricId,
            'metric_version' => $metricVersion,
            'project_uuid' => $projectUuid,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'dimensions' => $dimensions,
        ]), $privacyKey);
        $idempotencyKey = hash_hmac('sha256', 'privacy-query|' . $actorRef . '|' . $requestHash, $privacyKey);
        $now = $this->db->now();
        $stored = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,response_json,status_code,state,expires_at,created_at,updated_at) VALUES (%s,'privacy-query',%s,%s,%s,200,'completed',%s,%s,%s)",
            $idempotencyKey,
            $actorRef,
            $requestHash,
            Json::canonical($current),
            gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            $now,
            $now
        ));
        if ($stored === false) {
            return new WP_Error('smai_privacy_evidence_unavailable', 'Query privacy accounting could not be persisted.', ['status' => 503]);
        }
        if ($stored === 0) {
            $existingHash = $wpdb->get_var($wpdb->prepare("SELECT request_hash FROM `{$table}` WHERE idempotency_key=%s AND scope='privacy-query' AND actor_ref=%s AND state='completed'", $idempotencyKey, $actorRef));
            if (!is_string($existingHash) || !hash_equals($requestHash, $existingHash)) {
                return new WP_Error('smai_privacy_evidence_conflict', 'Query privacy accounting conflicted with existing evidence.', ['status' => 503]);
            }
        }
        return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @param array<string,mixed> $context */
    private function deny(string $code, string $message, string $metricId, string $metricVersion, string $projectUuid, int $actorUserId, string $purpose, array $context): WP_Error
    {
        $this->audit->log('metric_query_privacy_blocked', 'metric', $metricId . '@' . $metricVersion, 'blocked', array_merge([
            'project_uuid' => $projectUuid,
            'reason_code' => $code,
        ], $context), $purpose, null, $actorUserId);
        return new WP_Error($code, $message, ['status' => 403]);
    }
}
