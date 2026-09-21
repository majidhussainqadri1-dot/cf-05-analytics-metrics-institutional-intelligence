<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use WP_Error;

final class SnapshotService
{
    private Database $db;
    private LineageService $lineage;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->lineage = new LineageService($db);
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $dimensions */
    public function enqueue(string $metricId, string $version, string $start, string $end, array $dimensions, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_quality')) {
            return new WP_Error('smai_snapshot_forbidden', 'Snapshot computation is not authorized.', ['status'=>403]);
        }
        $startTs = $this->strictTimestamp($start);
        $endTs = $this->strictTimestamp($end);
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
            || preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+$/', $version) !== 1
            || $startTs === null || $endTs === null || $startTs >= $endTs
            || ($endTs - $startTs) > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_invalid_snapshot_window', 'Snapshot request is invalid.', ['status' => 400]);
        }
        $metric = (new MetricCatalog($this->db))->active($metricId, $version);
        if (!is_array($metric)) {
            return new WP_Error('smai_snapshot_metric_inactive', 'Snapshot metric is not active.', ['status' => 409]);
        }
        $definition = (array) $metric['definition'];
        $allowed = array_map('strval', (array) ($definition['dimensions'] ?? []));
        foreach ($dimensions as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)
                || (!is_scalar($value) && $value !== null)
                || (is_float($value) && !is_finite($value))) {
                return new WP_Error('smai_snapshot_dimension_denied', 'Snapshot dimensions are invalid or unapproved.', ['status' => 400]);
            }
        }
        if (PrivacyQueryPolicy::violations($definition, $dimensions) !== []) {
            return new WP_Error('smai_snapshot_privacy_policy_denied', 'Snapshot dimensions violate the metric privacy policy.', ['status' => 403]);
        }
        ksort($dimensions);
        return (new JobQueue($this->db))->enqueue('snapshot.compute', [
            'metric_id' => $metricId,
            'metric_version' => $version,
            'window_start' => gmdate('c', $startTs),
            'window_end' => gmdate('c', $endTs),
            'dimensions' => $dimensions,
            'actor_user_id' => $actorUserId,
        ], 'snapshot|' . $metricId . '|' . $version . '|' . gmdate('c', $startTs) . '|' . gmdate('c', $endTs) . '|' . hash('sha256', Json::canonical($dimensions)));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $metricId = (string) ($payload['metric_id'] ?? '');
        $version = (string) ($payload['metric_version'] ?? '');
        $actorUserId = (int) ($payload['actor_user_id'] ?? 0);
        $startTs = $this->strictTimestamp((string) ($payload['window_start'] ?? ''));
        $endTs = $this->strictTimestamp((string) ($payload['window_end'] ?? ''));
        $dimensions = is_array($payload['dimensions'] ?? null) ? $payload['dimensions'] : [];
        if ($actorUserId < 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
            || preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+$/', $version) !== 1
            || $startTs === null || $endTs === null || $startTs >= $endTs
            || ($endTs - $startTs) > 366 * DAY_IN_SECONDS) {
            throw new \InvalidArgumentException('Snapshot job contract is invalid.');
        }
        $start = gmdate('Y-m-d H:i:s', $startTs);
        $end = gmdate('Y-m-d H:i:s', $endTs);
        ksort($dimensions);
        $dimensionsJson = Json::canonical($dimensions);
        $dimensionsHash = hash('sha256', $dimensionsJson);

        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Snapshot transaction could not start.');
        }
        try {
            $metric = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$this->db->table('metrics')}` WHERE metric_id=%s AND metric_version=%s AND state='active' FOR UPDATE",
                $metricId,
                $version
            ), ARRAY_A);
            if (!is_array($metric)) {
                throw new \RuntimeException('Metric is not active.');
            }
            $definition = Json::object((string) $metric['definition_json']);
            if ($definition === []) {
                throw new \RuntimeException('Metric definition is unavailable.');
            }
            $allowed = array_map('strval', (array) ($definition['dimensions'] ?? []));
            foreach ($dimensions as $key => $dimensionValue) {
                if (!is_string($key) || !in_array($key, $allowed, true)
                    || (!is_scalar($dimensionValue) && $dimensionValue !== null)
                    || (is_float($dimensionValue) && !is_finite($dimensionValue))) {
                    throw new \RuntimeException('Unapproved snapshot dimension.');
                }
            }
            if (PrivacyQueryPolicy::violations($definition, $dimensions) !== []) {
                throw new \RuntimeException('Snapshot dimensions violate the metric privacy policy.');
            }

            $source = is_array($definition['source'] ?? null) ? $definition['source'] : [];
            $datasetId = (string) ($source['dataset_id'] ?? '');
            $datasetVersion = (string) ($source['dataset_version'] ?? '');
            $dataset = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s AND state='published' FOR UPDATE",
                $datasetId,
                $datasetVersion
            ), ARRAY_A);
            if (!is_array($dataset)) {
                throw new \RuntimeException('Metric source dataset is not published.');
            }
            $build = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$this->db->table('dataset_builds')}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 LIMIT 1 FOR UPDATE",
                $datasetId,
                $datasetVersion
            ), ARRAY_A);
            if (!is_array($build)) {
                throw new \RuntimeException('No active dataset build.');
            }

            $semantics = (string) ($definition['historical_semantics'] ?? 'current');
            $currentClause = $semantics === 'as_occurred' ? '' : " AND is_current=1";
            $stored = $wpdb->get_results($wpdb->prepare(
                "SELECT row_json,effective_from,is_current FROM `{$this->db->table('dataset_rows')}` WHERE build_uuid=%s AND effective_from>=%s AND effective_from<%s{$currentClause}",
                $build['build_uuid'],
                $start,
                $end
            ), ARRAY_A);
            if (!is_array($stored)) {
                throw new \RuntimeException('Snapshot source rows could not be read.');
            }
            $rows = [];
            $dataThrough = null;
            foreach ($stored as $item) {
                $row = Json::object((string) $item['row_json']);
                if (!$this->dimensionMatch($row, $dimensions)) {
                    continue;
                }
                $rows[] = $row;
                if ($dataThrough === null || (string) $item['effective_from'] > $dataThrough) {
                    $dataThrough = (string) $item['effective_from'];
                }
            }

            $calculation = is_array($definition['calculation'] ?? null) ? $definition['calculation'] : ['type' => 'count'];
            [$value,$numerator,$denominator] = $this->calculate($calculation, $rows);
            $cohortField = (string) ($definition['cohort_field'] ?? '');
            $cohort = count($rows);
            if ($cohortField !== '') {
                $set = [];
                foreach ($rows as $row) {
                    $v = $row[$cohortField] ?? null;
                    if ($v !== null && $v !== '') {
                        $set[Json::canonical($v)] = true;
                    }
                }
                $cohort = count($set);
            }
            $minimum = PrivacyQueryPolicy::effectiveMinimum(
                $definition,
                $dimensions,
                max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20))
            );
            $quality = match ((string) $dataset['quality_status']) {
                'green' => 'green',
                'amber' => 'warning',
                'red' => 'degraded',
                'warning' => 'warning',
                'degraded' => 'degraded',
                default => 'unknown',
            };
            $caveats = [];
            if ($dataThrough === null) {
                $quality = 'unknown';
                $caveats[] = 'No eligible source data was available for the requested window.';
            }
            $maxFreshness = max(60, (int) ($definition['freshness_seconds'] ?? 86400));
            if ($dataThrough !== null && time() - (int) strtotime($dataThrough) > $maxFreshness) {
                if ($quality === 'green') {
                    $quality = 'warning';
                }
                $caveats[] = 'Data is older than the declared freshness threshold.';
            }
            if (!in_array($quality, ['green','warning','degraded','unknown'], true)) {
                $quality = 'unknown';
            }
            if ($cohort < $minimum) {
                $quality = 'suppressed';
                $value = $numerator = $denominator = null;
                $caveats[] = 'Result suppressed below minimum cohort.';
            }
            if ((string) $dataset['quality_status'] !== 'green') {
                $caveats[] = 'Dataset quality is not green.';
            }
            $uncertainty = null;
            if (($calculation['type'] ?? '') === 'ratio' && $numerator !== null && $denominator !== null && $denominator > 0) {
                $uncertainty = Statistics::proportionInterval((int) round($numerator), (int) round($denominator));
            }

            $base = [
                'metric_id' => $metricId,
                'metric_version' => $version,
                'build_uuid' => $build['build_uuid'],
                'window_start' => $start,
                'window_end' => $end,
                'dimensions_hash' => $dimensionsHash,
                'dimensions_json' => $dimensionsJson,
                'value_decimal' => $value,
                'numerator_decimal' => $numerator,
                'denominator_decimal' => $denominator,
                'cohort_size' => $cohort,
                'quality_status' => $quality,
                'data_through' => $dataThrough,
                'coverage_decimal' => $this->coverage($cohort, $minimum, $quality),
                'uncertainty_json' => $uncertainty === null ? null : Json::encode($uncertainty),
                'caveats_json' => Json::encode(array_values(array_unique($caveats))),
                'state' => 'published',
                'created_at' => $this->db->now(),
            ];
            $table = $this->db->table('metric_snapshots');
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND window_start=%s AND window_end=%s AND dimensions_hash=%s ORDER BY snapshot_revision DESC LIMIT 1 FOR UPDATE",
                $metricId,
                $version,
                $start,
                $end,
                $dimensionsHash
            ), ARRAY_A);
            $revision = is_array($previous) ? (int) $previous['snapshot_revision'] + 1 : 1;
            $semantic = $base;
            unset($semantic['created_at']);
            $candidateHash = hash('sha256', Json::canonical($semantic));
            if (is_array($previous) && hash_equals((string) $previous['snapshot_hash'], $candidateHash)) {
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Snapshot no-change transaction could not be committed.');
                }
                return [
                    'snapshot_id' => (int) $previous['id'],
                    'snapshot_revision' => (int) $previous['snapshot_revision'],
                    'snapshot_hash' => $previous['snapshot_hash'],
                    'quality_status' => $previous['quality_status'],
                    'cohort_size' => (int) $previous['cohort_size'],
                    'value' => $previous['value_decimal'] !== null ? (float) $previous['value_decimal'] : null,
                    'unchanged' => true,
                ];
            }
            $record = array_merge($base, [
                'snapshot_revision' => $revision,
                'supersedes_snapshot_id' => is_array($previous) ? (int) $previous['id'] : null,
                'snapshot_hash' => $candidateHash,
            ]);
            if (is_array($previous) && (string) $previous['state'] === 'published') {
                $superseded = $wpdb->update($table, ['state' => 'superseded'], ['id' => (int) $previous['id'], 'state' => 'published']);
                if ($superseded !== 1) {
                    throw new \RuntimeException('Previous published snapshot could not be superseded.');
                }
            }
            if ($wpdb->insert($table, $record) !== 1) {
                throw new \RuntimeException('Metric snapshot could not be stored.');
            }
            $snapshotId = (int) $wpdb->insert_id;
            if (!$this->lineage->link('build', (string) $build['build_uuid'], null, 'snapshot', (string) $snapshotId, (string) $candidateHash, (string) $dataset['owner_module'], null, defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null)) {
                throw new \RuntimeException('Snapshot build lineage could not be recorded.');
            }
            if (!$this->lineage->link('metric', $metricId, $version, 'snapshot', (string) $snapshotId, (string) $candidateHash, (string) $metric['owner_module'])) {
                throw new \RuntimeException('Snapshot metric lineage could not be recorded.');
            }
            if (!$this->audit->logInOpenTransaction(
                'metric_snapshot_published',
                'metric_snapshot',
                (string) $snapshotId,
                'success',
                ['metric_id' => $metricId, 'metric_version' => $version, 'revision' => $revision, 'quality_status' => $quality, 'cohort_bucket' => $this->bucket($cohort)],
                'institutional_measurement',
                null,
                $actorUserId
            )) {
                throw new \RuntimeException('Snapshot audit evidence could not be recorded.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Snapshot transaction could not be committed.');
            }
            return [
                'snapshot_id' => $snapshotId,
                'snapshot_revision' => $revision,
                'snapshot_hash' => $candidateHash,
                'quality_status' => $quality,
                'cohort_size' => $cohort,
                'value' => $value,
                'unchanged' => false,
            ];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /** @param array<string,mixed> $calculation @param array<int,array<string,mixed>> $rows */
    private function calculate(array $calculation,array $rows):array
    {
        $type=(string)($calculation['type']??'count');
        if($type==='ratio'){$numFilters=is_array($calculation['numerator_filters']??null)?$calculation['numerator_filters']:[];$denFilters=is_array($calculation['denominator_filters']??null)?$calculation['denominator_filters']:[];$num=0;$den=0;
            foreach($rows as $row){$eligible=FilterEvaluator::matches($row,$denFilters);if($eligible){$den++;if(FilterEvaluator::matches($row,$numFilters)){$num++;}}}
            return[$den>0?$num/$den:null,(float)$num,(float)$den];}
        if(in_array($type,['sum','average'],true)){$field=(string)($calculation['field']??'');$filters=is_array($calculation['filters']??null)?$calculation['filters']:[];$values=[];foreach($rows as $row){if(FilterEvaluator::matches($row,$filters)){ $candidate=$row[$field]??null; if((is_int($candidate)||is_float($candidate))&&is_finite((float)$candidate)){$values[]=(float)$candidate;} }}$sum=array_sum($values);return[$type==='average'?Statistics::mean($values):$sum,$sum,(float)count($values)];}
        $filters=is_array($calculation['filters']??null)?$calculation['filters']:[];$count=0;foreach($rows as $row){if(FilterEvaluator::matches($row,$filters)){$count++;}}return[(float)$count,(float)$count,null];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $dimensions */
    private function dimensionMatch(array $row,array $dimensions):bool{foreach($dimensions as $key=>$value){if(($row[$key]??null)!==$value){return false;}}return true;}
    private function coverage(int $cohort,int $minimum,string $quality):float{if($quality==='suppressed'){return 0.0;}return min(1.0,$cohort/max(1,$minimum));}
    private function bucket(int $count):string{return match(true){$count<20=>'<20',$count<100=>'20-99',$count<1000=>'100-999',default=>'1000+'};}
    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/', $value, $match) !== 1
            || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}
