<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RateLimiter;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use WP_Error;

final class MetricQueryService
{
    private Database $db;
    private MetricCatalog $catalog;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->catalog = new MetricCatalog($db);
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,string|int|float|bool> $dimensions */
    public function query(
        string $metricId,
        string $version,
        string $windowStart,
        string $windowEnd,
        array $dimensions,
        int $actorUserId,
        string $purpose,
        string $projectUuid
    ): array|WP_Error {
        if (!RuntimeGate::queryEnabled()) {
            return new WP_Error('smai_query_disabled', 'Metric queries are disabled.', ['status' => 503]);
        }
        if ($actorUserId < 1 || strlen(trim($purpose)) < 8 || $projectUuid === '') {
            return new WP_Error('smai_query_context_required', 'Project and purpose context are required.', ['status' => 400]);
        }
        if (!(new RateLimiter($this->db))->consume('metric-query', (string) $actorUserId, 500, HOUR_IN_SECONDS)) {
            return new WP_Error('smai_query_rate_limited', 'Metric query rate limit exceeded.', ['status' => 429]);
        }

        $metric = $this->catalog->active($metricId, $version);
        if ($metric === null) {
            return new WP_Error('smai_metric_not_active', 'Metric version is not active.', ['status' => 404]);
        }
        $definition = (array) $metric['definition'];
        $datasetRef = 'metric:' . $metricId . '@' . $version;
        if (!(new AccessProjectService($this->db))->authorize($projectUuid, $actorUserId, $datasetRef, [], $purpose)) {
            return new WP_Error('smai_query_access_denied', 'Access project does not authorize this metric.', ['status' => 403]);
        }

        $allowedDimensions = array_map('strval', (array) ($definition['dimensions'] ?? []));
        foreach (array_keys($dimensions) as $dimension) {
            if (!in_array((string) $dimension, $allowedDimensions, true)) {
                return new WP_Error('smai_dimension_not_allowed', 'A requested dimension is not approved for this metric.', ['status' => 403]);
            }
        }
        $startTs = strtotime($windowStart);
        $endTs = strtotime($windowEnd);
        if ($startTs === false || $endTs === false || $startTs >= $endTs || ($endTs - $startTs) > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_invalid_window', 'Metric window is invalid.', ['status' => 400]);
        }

        ksort($dimensions);
        $dimensionsJson = Json::canonical($dimensions);
        $dimensionsHash = hash('sha256', $dimensionsJson);
        $table = $this->db->table('metric_snapshots');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND window_start=%s AND window_end=%s AND dimensions_hash=%s AND state='published' ORDER BY snapshot_revision DESC LIMIT 1",
            $metricId,
            $version,
            gmdate('Y-m-d H:i:s', $startTs),
            gmdate('Y-m-d H:i:s', $endTs),
            $dimensionsHash
        ), ARRAY_A);

        if (!is_array($row)) {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'not_found', [
                'project_uuid' => $projectUuid,
                'dimension_names' => array_keys($dimensions),
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_snapshot_not_found', 'No approved snapshot exists for this exact metric version and window.', ['status' => 404]);
        }

        $minimum = max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20));
        if ((int) $row['cohort_size'] < $minimum || (string) $row['quality_status'] === 'suppressed') {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'suppressed', [
                'project_uuid' => $projectUuid,
                'dimension_names' => array_keys($dimensions),
                'minimum_cohort' => $minimum,
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_cohort_suppressed', 'Result is suppressed by the minimum cohort policy.', ['status' => 403]);
        }

        $response = [
            'metric_id' => $metricId,
            'metric_version' => $version,
            'snapshot_revision' => (int) ($row['snapshot_revision'] ?? 1),
            'name' => $metric['name'],
            'definition_hash' => $metric['definition_hash'],
            'business_question' => $metric['business_question'],
            'window' => [
                'start' => gmdate('c', $startTs),
                'end' => gmdate('c', $endTs),
            ],
            'dimensions' => Json::object((string) $row['dimensions_json']),
            'value' => $row['value_decimal'] !== null ? (float) $row['value_decimal'] : null,
            'numerator' => $row['numerator_decimal'] !== null ? (float) $row['numerator_decimal'] : null,
            'denominator' => $row['denominator_decimal'] !== null ? (float) $row['denominator_decimal'] : null,
            'cohort_size' => (int) $row['cohort_size'],
            'minimum_cohort' => $minimum,
            'quality_status' => $row['quality_status'],
            'data_through' => $row['data_through'] ? gmdate('c', (int) strtotime((string) $row['data_through'])) : null,
            'freshness_seconds' => $row['data_through'] ? max(0, time() - (int) strtotime((string) $row['data_through'])) : null,
            'coverage' => $row['coverage_decimal'] !== null ? (float) $row['coverage_decimal'] : null,
            'uncertainty' => Json::object((string) ($row['uncertainty_json'] ?? '{}')),
            'caveats' => Json::list((string) ($row['caveats_json'] ?? '[]')),
            'source_owner' => $metric['owner_module'],
            'status' => $row['quality_status'] === 'green' ? 'current_within_declared_quality' : 'unknown_or_degraded',
        ];

        $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'success', [
            'project_uuid' => $projectUuid,
            'dimension_names' => array_keys($dimensions),
            'quality_status' => $row['quality_status'],
            'result_size' => 1,
            'cohort_size_bucket' => $this->bucket((int) $row['cohort_size']),
        ], $purpose, null, $actorUserId);

        return $response;
    }

    private function bucket(int $count): string
    {
        return match (true) {
            $count < 20 => '<20',
            $count < 100 => '20-99',
            $count < 1000 => '100-999',
            default => '1000+',
        };
    }
}
