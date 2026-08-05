<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
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
    public function query(string $metricId, string $version, string $windowStart, string $windowEnd, array $dimensions, int $actorUserId, string $purpose): array|WP_Error
    {
        if (!RuntimeGate::queryEnabled()) {
            return new WP_Error('smai_query_disabled', 'Metric queries are disabled.', ['status' => 503]);
        }

        $metric = $this->catalog->active($metricId, $version);
        if ($metric === null) {
            return new WP_Error('smai_metric_not_active', 'Metric version is not active.', ['status' => 404]);
        }
        $definition = (array) $metric['definition'];
        $allowedDimensions = array_map('strval', (array) ($definition['dimensions'] ?? []));
        foreach (array_keys($dimensions) as $dimension) {
            if (!in_array((string) $dimension, $allowedDimensions, true)) {
                return new WP_Error('smai_dimension_not_allowed', 'A requested dimension is not approved for this metric.', ['status' => 403]);
            }
        }
        if (strtotime($windowStart) === false || strtotime($windowEnd) === false || strtotime($windowStart) >= strtotime($windowEnd)) {
            return new WP_Error('smai_invalid_window', 'Metric window is invalid.', ['status' => 400]);
        }

        ksort($dimensions);
        $dimensionsJson = wp_json_encode($dimensions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $dimensionsHash = hash('sha256', (string) $dimensionsJson);
        $table = $this->db->table('metric_snapshots');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND window_start=%s AND window_end=%s AND dimensions_hash=%s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $metricId,
            $version,
            gmdate('Y-m-d H:i:s', (int) strtotime($windowStart)),
            gmdate('Y-m-d H:i:s', (int) strtotime($windowEnd)),
            $dimensionsHash
        ), ARRAY_A);

        if (!is_array($row)) {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'not_found', [
                'dimension_names' => array_keys($dimensions),
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_snapshot_not_found', 'No approved snapshot exists for this exact metric version and window.', ['status' => 404]);
        }

        $minimum = max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20));
        if ((int) $row['cohort_size'] < $minimum) {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'suppressed', [
                'dimension_names' => array_keys($dimensions),
                'minimum_cohort' => $minimum,
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_cohort_suppressed', 'Result is suppressed by the minimum cohort policy.', ['status' => 403]);
        }

        $response = [
            'metric_id' => $metricId,
            'metric_version' => $version,
            'name' => $metric['name'],
            'window' => ['start' => gmdate('c', (int) strtotime((string) $row['window_start'])), 'end' => gmdate('c', (int) strtotime((string) $row['window_end']))],
            'dimensions' => json_decode((string) $row['dimensions_json'], true),
            'value' => $row['value_decimal'] !== null ? (float) $row['value_decimal'] : null,
            'numerator' => $row['numerator_decimal'] !== null ? (float) $row['numerator_decimal'] : null,
            'denominator' => $row['denominator_decimal'] !== null ? (float) $row['denominator_decimal'] : null,
            'cohort_size' => (int) $row['cohort_size'],
            'quality_status' => $row['quality_status'],
            'data_through' => $row['data_through'] ? gmdate('c', (int) strtotime((string) $row['data_through'])) : null,
            'freshness' => $row['data_through'] ? max(0, time() - (int) strtotime((string) $row['data_through'])) : null,
            'caveats' => json_decode((string) ($row['caveats_json'] ?? '[]'), true),
            'definition_hash' => $metric['definition_hash'],
            'source_owner' => $metric['owner_module'],
            'status' => $row['quality_status'] === 'green' ? 'current_within_declared_quality' : 'unknown_or_degraded',
        ];

        $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'success', [
            'dimension_names' => array_keys($dimensions),
            'quality_status' => $row['quality_status'],
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
