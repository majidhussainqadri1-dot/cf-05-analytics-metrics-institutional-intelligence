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
        // Downstream snapshots and queries must have immutable, ordered UTC
        // windows; relative or corrupt dates must not disable differencing.
        $utcFormat = '/^[0-9]{4}-[0-9]{2}-[0-9]{2} (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D';
        if (preg_match($utcFormat, $windowStart) !== 1 || preg_match($utcFormat, $windowEnd) !== 1) {
            return new WP_Error('smai_privacy_window_invalid', 'A canonical ordered UTC privacy window is required.', ['status' => 503]);
        }
        $startUtc = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $windowStart, new \DateTimeZone('UTC'));
        $endUtc = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $windowEnd, new \DateTimeZone('UTC'));
        if ($startUtc === false || $endUtc === false
            || $startUtc->format('Y-m-d H:i:s') !== $windowStart
            || $endUtc->format('Y-m-d H:i:s') !== $windowEnd
            || $startUtc->getTimestamp() >= $endUtc->getTimestamp()) {
            return new WP_Error('smai_privacy_window_invalid', 'A canonical ordered UTC privacy window is required.', ['status' => 503]);
        }
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
            'slice_fingerprint' => hash_hmac('sha256', $windowStart . '|' . $windowEnd . '|' . hash_hmac('sha256', Json::canonical($dimensions), $privacyKey), $privacyKey),
            'cohort_size' => $cohortSize,
            'privacy_cost' => $cost,
        ];

        // Share slice/differencing budgets across viewers of a governed project; actor identity remains in the audit trail.
        $actorRef = hash_hmac('sha256', 'query|project|' . $projectUuid . '|' . $metricId . '@' . $metricVersion, $privacyKey);
        $table = $this->db->table('idempotency_keys');
        $wpdb = $this->db->wpdb();
        $lockName = 'smai_privacy_' . substr(hash_hmac('sha256', $actorRef, $privacyKey), 0, 48);
        $lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lockName));
        if ($lock !== 1) {
            return new WP_Error('smai_privacy_lock_unavailable', 'Query privacy accounting is temporarily unavailable.', ['status' => 503]);
        }
        try {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT request_hash,response_json,created_at FROM `{$table}` WHERE scope='privacy-query' AND actor_ref=%s AND state='completed' AND created_at>=%s ORDER BY created_at DESC LIMIT 1001",
            $actorRef,
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ), ARRAY_A);
        // A failed or truncated ledger cannot be treated as an empty privacy budget.
        if (!is_array($rows) || count($rows) > 1000) {
            return new WP_Error('smai_privacy_evidence_unavailable', 'Query privacy accounting could not be verified.', ['status' => 503]);
        }

        $fingerprints = [];
        $budget = 0;
        foreach ($rows as $row) {
            $previous = is_array($row) ? Json::object((string) ($row['response_json'] ?? '')) : [];
            $currentFingerprintValid = is_string($previous['slice_fingerprint'] ?? null)
                && preg_match('/^[0-9a-f]{64}$/', $previous['slice_fingerprint']) === 1;
            // Existing older rows may have only a window + dimension fingerprint.
            $legacyFingerprintValid = !isset($previous['slice_fingerprint'])
                && is_string($previous['dimensions_fingerprint'] ?? null)
                && preg_match('/^[0-9a-f]{64}$/', $previous['dimensions_fingerprint']) === 1
                && is_string($previous['window_start'] ?? null)
                && is_string($previous['window_end'] ?? null)
                && strtotime($previous['window_start']) !== false
                && strtotime($previous['window_end']) !== false;
            // An intact fingerprint is insufficient when other required slice
            // dimensions or window evidence is missing. Differencing must
            // never be silently downgraded by corrupted historical records.
            $priorStart = $previous['window_start'] ?? null;
            $priorEnd = $previous['window_end'] ?? null;
            $priorStartTs = is_string($priorStart) ? strtotime($priorStart) : false;
            $priorEndTs = is_string($priorEnd) ? strtotime($priorEnd) : false;
            $priorNames = $previous['dimension_names'] ?? null;
            $priorHashes = $previous['dimension_hashes'] ?? null;
            $priorShapeValid = is_array($priorNames) && array_is_list($priorNames)
                && count($priorNames) <= 10 && is_array($priorHashes)
                && $priorStartTs !== false && $priorEndTs !== false && $priorStartTs < $priorEndTs;
            if ($priorShapeValid) {
                $distinctNames = [];
                foreach ($priorNames as $name) {
                    if (!is_string($name) || !array_key_exists($name, $priorHashes)
                        || !is_string($priorHashes[$name])
                        || preg_match('/^[0-9a-f]{64}$/D', $priorHashes[$name]) !== 1
                        || isset($distinctNames[$name])) {
                        $priorShapeValid = false;
                        break;
                    }
                    $distinctNames[$name] = true;
                }
                if (count($priorHashes) !== count($distinctNames)) {
                    $priorShapeValid = false;
                }
            }
            if (!$priorShapeValid) {
                return new WP_Error('smai_privacy_evidence_unavailable', 'Stored privacy accounting evidence is incomplete.', ['status' => 503]);
            }
            if ((!$currentFingerprintValid && !$legacyFingerprintValid)
                || !isset($previous['cohort_size'], $previous['privacy_cost'])
                || !is_numeric($previous['cohort_size']) || !is_numeric($previous['privacy_cost'])
                || (int) $previous['privacy_cost'] < 0) {
                return new WP_Error('smai_privacy_evidence_unavailable', 'Stored privacy accounting evidence is invalid.', ['status' => 503]);
            }
            $dimensionFingerprint = (string) ($previous['dimensions_fingerprint'] ?? '');
            $fingerprint = (string) ($previous['slice_fingerprint'] ?? '');
            if ($fingerprint === '' && $dimensionFingerprint !== '') {
                $fingerprint = hash_hmac('sha256', (string) ($previous['window_start'] ?? '') . '|' . (string) ($previous['window_end'] ?? '') . '|' . $dimensionFingerprint, $privacyKey);
            }
            if ($fingerprint !== '') { $fingerprints[$fingerprint] = true; }
            $budget += max(0, (int) ($previous['privacy_cost'] ?? 0));
            if (PrivacyQueryPolicy::differencingRisk($current, $previous, $differencingFloor)) {
                return $this->deny('smai_differencing_risk', 'Query blocked because it could isolate a small cohort by differencing recent slices.', $metricId, $metricVersion, $projectUuid, $actorUserId, $purpose, ['minimum_difference' => $differencingFloor]);
            }
        }

        $exactRepeated = isset($fingerprints[$current['slice_fingerprint']]);
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
        // Old keys survive the rolling window until retention runs. Rotate the
        // key hourly so expired evidence cannot suppress a current witness.
        $ledgerHour = intdiv(time(), HOUR_IN_SECONDS);
        $idempotencyKey = hash_hmac('sha256', 'privacy-query|' . $actorRef . '|' . $ledgerHour . '|' . $requestHash, $privacyKey);
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
