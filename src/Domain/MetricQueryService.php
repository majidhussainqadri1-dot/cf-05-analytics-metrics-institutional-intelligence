<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RateLimiter;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
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

    /** @param array<string,string|int|float|bool|null> $dimensions */
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
        $purpose = Text::truncate(trim(wp_strip_all_tags($purpose)), 2000);
        if ($actorUserId < 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1
            || preg_match('/^[0-9a-f-]{36}$/i', $projectUuid) !== 1
            || strlen($purpose) < 12
            || (new SensitiveValueDetector())->violations($purpose) !== []) {
            return new WP_Error('smai_query_context_required', 'Metric, project and purpose context are invalid.', ['status' => 400]);
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
            if (!is_string($dimension) || !in_array($dimension, $allowedDimensions, true)) {
                return new WP_Error('smai_dimension_not_allowed', 'A requested dimension is not approved for this metric.', ['status' => 403]);
            }
        }
        $policyViolations = PrivacyQueryPolicy::violations($definition, $dimensions);
        if ($policyViolations !== []) {
            return new WP_Error('smai_query_privacy_policy', 'Requested dimensions violate the metric privacy policy.', ['status' => 403, 'violations' => $policyViolations]);
        }

        $startTs = $this->strictTimestamp($windowStart);
        $endTs = $this->strictTimestamp($windowEnd);
        if ($startTs === null || $endTs === null || $startTs >= $endTs || ($endTs - $startTs) > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_invalid_window', 'Metric window is invalid.', ['status' => 400]);
        }
        $canonicalStart = gmdate('Y-m-d H:i:s', $startTs);
        $canonicalEnd = gmdate('Y-m-d H:i:s', $endTs);

        ksort($dimensions);
        $dimensionsJson = Json::canonical($dimensions);
        $dimensionsHash = hash('sha256', $dimensionsJson);
        $table = $this->db->table('metric_snapshots');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND window_start=%s AND window_end=%s AND dimensions_hash=%s AND state='published' ORDER BY snapshot_revision DESC LIMIT 1",
            $metricId,
            $version,
            $canonicalStart,
            $canonicalEnd,
            $dimensionsHash
        ), ARRAY_A);

        if (!is_array($row)) {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'not_found', [
                'project_uuid' => $projectUuid,
                'dimension_names' => array_keys($dimensions),
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_snapshot_not_found', 'No approved snapshot exists for this exact metric version and window.', ['status' => 404]);
        }

        $minimum = PrivacyQueryPolicy::effectiveMinimum(
            $definition,
            $dimensions,
            max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20))
        );
        $storedQuality = (string) $row['quality_status'];
        if ((int) $row['cohort_size'] < $minimum || $storedQuality === 'suppressed') {
            $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'suppressed', [
                'project_uuid' => $projectUuid,
                'dimension_names' => array_keys($dimensions),
                'minimum_cohort' => $minimum,
            ], $purpose, null, $actorUserId);
            return new WP_Error('smai_cohort_suppressed', 'Result is suppressed by the minimum cohort policy.', ['status' => 403]);
        }
        if ($storedQuality === 'invalidated') {
            return new WP_Error('smai_snapshot_invalidated', 'The matching snapshot has been invalidated and cannot be disclosed.', ['status' => 409]);
        }

        $privacy = (new QueryPrivacyGuard($this->db))->evaluateAndRecord(
            $metricId,
            $version,
            $projectUuid,
            $actorUserId,
            $purpose,
            $canonicalStart,
            $canonicalEnd,
            $dimensions,
            $definition,
            (int) $row['cohort_size'],
            $minimum
        );
        if (is_wp_error($privacy)) {
            return $privacy;
        }

        $quality = in_array($storedQuality, ['green','warning','degraded','unknown'], true) ? $storedQuality : 'unknown';
        $caveats = Json::list((string) ($row['caveats_json'] ?? '[]'));
        $dataThroughTimestamp = $row['data_through'] ? strtotime((string) $row['data_through']) : false;
        $freshnessSeconds = $dataThroughTimestamp !== false ? max(0, time() - $dataThroughTimestamp) : null;
        $declaredFreshness = max(60, (int) ($definition['freshness_seconds'] ?? DAY_IN_SECONDS));
        if ($freshnessSeconds === null) {
            $quality = 'unknown';
            $caveats[] = 'Data-through time is unavailable.';
        } elseif ($freshnessSeconds > $declaredFreshness) {
            if ($quality === 'green') {
                $quality = 'warning';
            }
            $caveats[] = 'Data is older than the declared freshness threshold.';
        }
        $caveats = array_values(array_unique(array_map('strval', $caveats)));

        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_query_privacy_key_missing', 'Metric result was withheld because keyed privacy evidence is unavailable.', ['status' => 503]);
        }
        $fingerprint = hash_hmac('sha256', $dimensionsJson, SMAI_PSEUDONYM_KEY);
        $logged = $this->audit->log('metric_query', 'metric_snapshot', $metricId . '@' . $version, 'success', [
            'project_uuid' => $projectUuid,
            'dimension_names' => array_keys($dimensions),
            'dimensions_fingerprint' => $fingerprint,
            'quality_status' => $quality,
            'result_size' => 1,
            'cohort_size_bucket' => $this->bucket((int) $row['cohort_size']),
        ], $purpose, null, $actorUserId);
        if (!$logged) {
            return new WP_Error('smai_query_audit_unavailable', 'Metric result was withheld because audit evidence could not be recorded.', ['status' => 503]);
        }

        return [
            'metric_id' => $metricId,
            'metric_version' => $version,
            'snapshot_revision' => (int) ($row['snapshot_revision'] ?? 1),
            'name' => $metric['name'],
            'definition_hash' => $metric['definition_hash'],
            'business_question' => $metric['business_question'],
            'window' => ['start' => gmdate('c', $startTs), 'end' => gmdate('c', $endTs)],
            'dimensions' => Json::object((string) $row['dimensions_json']),
            'value' => $row['value_decimal'] !== null ? (float) $row['value_decimal'] : null,
            'numerator' => $row['numerator_decimal'] !== null ? (float) $row['numerator_decimal'] : null,
            'denominator' => $row['denominator_decimal'] !== null ? (float) $row['denominator_decimal'] : null,
            'cohort_size' => (int) $row['cohort_size'],
            'minimum_cohort' => $minimum,
            'quality_status' => $quality,
            'data_through' => $dataThroughTimestamp !== false ? gmdate('c', $dataThroughTimestamp) : null,
            'freshness_seconds' => $freshnessSeconds,
            'coverage' => $row['coverage_decimal'] !== null ? (float) $row['coverage_decimal'] : null,
            'uncertainty' => Json::object((string) ($row['uncertainty_json'] ?? '{}')),
            'caveats' => $caveats,
            'source_owner' => $metric['owner_module'],
            'status' => $quality === 'green' ? 'current_within_declared_quality' : 'unknown_or_degraded',
        ];
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

    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}
