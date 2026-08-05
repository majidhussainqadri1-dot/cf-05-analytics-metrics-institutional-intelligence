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

        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $differencingFloor = max($minimum, (int) ($controls['differencing_floor'] ?? $minimum));
        $maxSlices = max(5, min(200, (int) ($controls['max_distinct_slices_per_hour'] ?? 30)));
        $maxBudget = max(10, min(1000, (int) ($controls['max_privacy_budget_per_hour'] ?? 80)));
        $cost = PrivacyQueryPolicy::privacyCost($definition, $dimensions);

        ksort($dimensions);
        $dimensionHashes = [];
        foreach ($dimensions as $name => $value) {
            $dimensionHashes[(string) $name] = hash('sha256', Json::canonical($value));
        }
        $current = [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'dimension_names' => array_values(array_map('strval', array_keys($dimensions))),
            'dimension_hashes' => $dimensionHashes,
            'dimensions_fingerprint' => hash('sha256', Json::canonical($dimensions)),
            'cohort_size' => $cohortSize,
            'privacy_cost' => $cost,
        ];

        $actorRef = hash('sha256', 'query|' . $actorUserId . '|' . $projectUuid . '|' . $metricId . '@' . $metricVersion);
        $table = $this->db->table('idempotency_keys');
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

        $requestHash = hash('sha256', Json::canonical([
            'metric_id' => $metricId,
            'metric_version' => $metricVersion,
            'project_uuid' => $projectUuid,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'dimensions' => $dimensions,
        ]));
        $idempotencyKey = hash('sha256', 'privacy-query|' . $actorRef . '|' . $requestHash);
        $now = $this->db->now();
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,response_json,status_code,state,expires_at,created_at,updated_at) VALUES (%s,'privacy-query',%s,%s,%s,200,'completed',%s,%s,%s)",
            $idempotencyKey,
            $actorRef,
            $requestHash,
            Json::canonical($current),
            gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            $now,
            $now
        ));

        return true;
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
