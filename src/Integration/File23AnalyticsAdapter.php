<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Integration;

use Sabri\AnalyticsIntelligence\Domain\MetricQueryService;
use Sabri\AnalyticsIntelligence\Domain\MetricCatalog;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;
use WP_Error;

/**
 * File 23's actual native 2.0.0 adapter contract, and its optional
 * SPDB_Analytics_Provider interface, are required before loading this class.
 *
 * This bridge owns no native data, no publishing/ranking operations and no
 * cross-user "own" projections. It only obtains independently authorized,
 * canonical CF-05 MetricQueryService aggregates for institutional reporting.
 */
final class File23AnalyticsAdapter implements \SPDB_Provider_Adapter, \SPDB_Analytics_Provider
{
    public const PROVIDER_KEY = 'cf05_analytics';
    public const FILE23_ADAPTER_CONTRACT = '2.0.0';
    public const MAX_METRICS = 20;

    public function __construct(private Database $db)
    {
    }

    public function get_provider_key(): string
    {
        return self::PROVIDER_KEY;
    }

    public function get_provider_name(): string
    {
        return 'CF-05 Governed Institutional Analytics';
    }

    public function get_provider_version(): string
    {
        return SMAI_VERSION;
    }

    public function get_minimum_contract_version(): string
    {
        return self::FILE23_ADAPTER_CONTRACT;
    }

    public function get_maximum_contract_version(): string
    {
        return self::FILE23_ADAPTER_CONTRACT;
    }

    public function get_declared_capability_state(): string
    {
        return RuntimeGate::queryEnabled() ? 'read_only' : 'unavailable';
    }

    public function get_object_types(): array
    {
        return ['analytics_metric'];
    }

    public function get_privacy_classifications(): array
    {
        return ['restricted'];
    }

    public function get_supported_capabilities(): array
    {
        return ['spdb_view_global_analytics'];
    }

    public function get_operation_definitions(): array
    {
        return [];
    }

    public function health_check(): array
    {
        return [
            'owner' => 'cf-05',
            'contract_version' => SMAI_CONTRACT_VERSION,
            'file23_contract' => self::FILE23_ADAPTER_CONTRACT,
            'capability' => $this->get_declared_capability_state(),
            'source_state' => RuntimeGate::state(),
            'source_schema_ready' => RuntimeGate::schemaReady(),
            'authorization_owner' => 'file-00-and-file-23',
            'aggregate_queries_only' => true,
            'cross_user_own_metrics_supported' => false,
            'staging_accepted' => false,
            'live_verified' => false,
        ];
    }

    public function list_items(array $query): WP_Error
    {
        return $this->unsupported();
    }

    public function get_item(string $object_type, string $object_id): WP_Error
    {
        return $this->unsupported();
    }

    public function get_allowed_operations(string $object_type, string $object_id): array
    {
        return [];
    }

    public function execute_operation(string $operation_key, string $object_type, string $object_id, array $payload): WP_Error
    {
        return $this->unsupported();
    }

    /**
     * File 23 produces the query context itself. Even so, validate it again:
     * never trust an upstream-supplied user ID, scope, or authorization alone.
     *
     * A native-owner-authorized filter supplies minimal metric grants:
     *   metric_id, metric_version, project_uuid, purpose, source_owner, unit.
     * This plugin does not invent a project, expose raw rows, or silently
     * choose a metric or backfill an untracked date. CF-05's normal project,
     * source-privacy, cohort, budget and audit checks still run on every query.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>|WP_Error
     */
    public function get_spdb_aggregate_metrics(array $query): array|WP_Error
    {
        $viewer = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($viewer < 1 || !isset($query['viewer_user_id']) || !is_int($query['viewer_user_id'])
            || $query['viewer_user_id'] !== $viewer) {
            return new WP_Error('smai_file23_viewer_mismatch', 'Analytics viewer identity was not verified.', ['status' => 403]);
        }

        // File 23 has no native object-owner IDs in its current analytics query
        // context. Treat "own" as unsupported, not as implicit institution.
        if (($query['scope'] ?? null) !== 'institution') {
            return new WP_Error('smai_file23_own_scope_unavailable', 'Object-owned aggregate analytics require a reviewed native owner projection.', ['status' => 403]);
        }
        if (!RuntimeGate::queryEnabled()
            || !class_exists('\SPDB_Capabilities')
            || !\SPDB_Capabilities::current_user_can('spdb_view_global_analytics')
            || !user_can($viewer, 'smai_query_metrics')) {
            return new WP_Error('smai_file23_access_denied', 'Governed analytics is unavailable or not authorized.', ['status' => 403]);
        }

        $start = self::strictDay($query['date_from'] ?? null);
        $last = self::strictDay($query['date_to'] ?? null);
        if ($start === null || $last === null || $start > $last) {
            return new WP_Error('smai_file23_window_required', 'An exact approved date range is required.', ['status' => 400]);
        }
        $from = new \DateTimeImmutable($start . ' 00:00:00', new \DateTimeZone('UTC'));
        $end = (new \DateTimeImmutable($last . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day');
        if ($end->getTimestamp() - $from->getTimestamp() > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_file23_window_too_large', 'The authorized analytics window is too large.', ['status' => 400]);
        }

        // Native approved CF-05 access projects are the source of truth;
        // no external filter is required to discover authorized metrics.
        $nativeGrants = $this->nativeApprovedGrants($viewer);
        if (is_wp_error($nativeGrants)) {
            return $nativeGrants;
        }
        $grants = apply_filters('smai_file23_approved_metric_grants', $nativeGrants, $viewer, 'institution');
        if (!is_array($grants) || count($grants) > self::MAX_METRICS) {
            return new WP_Error('smai_file23_grants_invalid', 'The approved metric registry is unavailable or exceeds policy.', ['status' => 503]);
        }

        // Validate every grant before the first read: a malformed later grant
        // must not cause earlier privacy-budget consumption or partial output.
        $validated = [];
        foreach ($grants as $grant) {
            $checked = self::approvedGrant($grant);
            if ($checked === null) {
                return new WP_Error('smai_file23_grant_invalid', 'An institutional metric grant is incomplete or invalid.', ['status' => 503]);
            }
            $identity = $checked['metric_id'] . '@' . $checked['metric_version'];
            if (isset($validated[$identity])) {
                return new WP_Error('smai_file23_duplicate_grant', 'An institutional metric was granted more than once.', ['status' => 503]);
            }
            $validated[$identity] = $checked;
        }

        // An extension may narrow the native grant list, never enlarge it.
        // Project existence and metrics were derived from the exact owner DB;
        // bypassing that inventory through a filter would reintroduce a
        // cross-module authorization path without native owner approval.
        $nativeIndex = [];
        foreach ($nativeGrants as $granted) {
            $nativeIndex[$granted['metric_id'] . '@' . $granted['metric_version']] = $granted;
        }
        foreach ($validated as $identity => $checked) {
            $owned = $nativeIndex[$identity] ?? null;
            if (!is_array($owned) || $owned !== $checked) {
                return new WP_Error('smai_file23_unapproved_grant', 'A metric grant does not match the current native access-project inventory.', ['status' => 403]);
            }
        }

        $entries = [];
        foreach ($validated as $checked) {
            $result = (new MetricQueryService($this->db))->query(
                $checked['metric_id'],
                $checked['metric_version'],
                $from->format('Y-m-d\TH:i:s\Z'),
                $end->format('Y-m-d\TH:i:s\Z'),
                [],
                $viewer,
                $checked['purpose'],
                $checked['project_uuid']
            );
            // Missing snapshots, exhausted privacy budget and small cohorts
            // must never become fabricated zeroes or inferred negative results.
            if (is_wp_error($result)) {
                continue;
            }
            $public = self::presentApprovedAggregate($result, $checked, $from, $end);
            if ($public !== null) {
                $entries[] = $public;
            }
        }
        return [
            'metrics' => $entries,
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Discover only active, independently approved CF-05 access projects
     * owned by this exact actor. Every discovered grant is still rechecked
     * by MetricQueryService::query against the canonical project and metric.
     * Unknown SQL results never mean the user has "zero" projects.
     *
     * @return array<int,array<string,string>>|WP_Error
     */
    private function nativeApprovedGrants(int $viewer): array|WP_Error
    {
        if ($viewer < 1) {
            return new WP_Error('smai_file23_invalid_viewer', 'The metric owner is invalid.', ['status' => 403]);
        }
        $wpdb = $this->db->wpdb();
        $projects = $wpdb->get_results($wpdb->prepare(
            "SELECT project_uuid,purpose,datasets_json FROM `{$this->db->table('access_projects')}` WHERE owner_user_id=%d AND state='active' AND expires_at>%s ORDER BY id DESC LIMIT 101",
            $viewer,
            $this->db->now()
        ), ARRAY_A);
        if (!is_array($projects) || count($projects) > 100) {
            return new WP_Error('smai_file23_project_inventory_unavailable', 'Authorized metric project inventory cannot be trusted.', ['status' => 503]);
        }
        $catalog = new MetricCatalog($this->db);
        $approved = [];
        foreach ($projects as $project) {
            if (!is_array($project) || !is_string($project['project_uuid'] ?? null)
                || !is_string($project['purpose'] ?? null)
                || !is_string($project['datasets_json'] ?? null)) {
                return new WP_Error('smai_file23_project_corrupt', 'Approved analytics project evidence is incomplete.', ['status' => 503]);
            }
            $refs = json_decode($project['datasets_json'], true);
            if (!is_array($refs) || !array_is_list($refs) || count($refs) > 100) {
                return new WP_Error('smai_file23_project_refs_corrupt', 'Analytics access grants are malformed or unbounded.', ['status' => 503]);
            }
            foreach ($refs as $reference) {
                if (!is_string($reference) || !str_starts_with($reference, 'metric:')) {
                    continue;
                }
                if (preg_match('/^metric:([a-z][a-z0-9_.-]{2,189})@([0-9]+\.[0-9]+\.[0-9]+)$/D', $reference, $match) !== 1) {
                    return new WP_Error('smai_file23_metric_ref_invalid', 'A stored metric grant has an invalid source reference.', ['status' => 503]);
                }
                $key = $match[1] . '@' . $match[2];
                if (isset($approved[$key])) {
                    continue;
                }
                $metric = $catalog->active($match[1], $match[2]);
                if ($metric === null) {
                    if (trim((string) ($wpdb->last_error ?? '')) !== '') {
                        return new WP_Error('smai_file23_metric_catalog_unavailable', 'Approved metric catalog could not be read safely.', ['status' => 503]);
                    }
                    // An inactive/deprecated metric is never rediscovered.
                    continue;
                }
                $unit = $metric['definition']['unit'] ?? null;
                $candidate = [
                    'metric_id' => $match[1],
                    'metric_version' => $match[2],
                    'project_uuid' => $project['project_uuid'],
                    'purpose' => $project['purpose'],
                    'source_owner' => (string) ($metric['owner_module'] ?? ''),
                    'unit' => is_string($unit) ? $unit : '',
                ];
                if (self::approvedGrant($candidate) === null) {
                    return new WP_Error('smai_file23_metric_contract_corrupt', 'Metric project contract cannot be projected safely.', ['status' => 503]);
                }
                $approved[$key] = $candidate;
                if (count($approved) > self::MAX_METRICS) {
                    return new WP_Error('smai_file23_metric_grants_exceeded', 'Analytics metric scope exceeds a bounded dashboard batch.', ['status' => 503]);
                }
            }
        }
        return array_values($approved);
    }

    private function unsupported(): WP_Error
    {
        return new WP_Error('smai_file23_readonly', 'CF-05 does not provide File 23 native operations or objects.', ['status' => 403]);
    }

    private static function strictDay(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @return array{metric_id:string,metric_version:string,project_uuid:string,purpose:string,source_owner:string,unit:string}|null
     */
    private static function approvedGrant(mixed $grant): ?array
    {
        if (!is_array($grant)
            || array_diff(array_keys($grant), ['metric_id','metric_version','project_uuid','purpose','source_owner','unit']) !== []
            || !isset($grant['metric_id'], $grant['metric_version'], $grant['project_uuid'], $grant['purpose'], $grant['source_owner'], $grant['unit'])) {
            return null;
        }
        foreach ($grant as $value) {
            if (!is_string($value)) {
                return null;
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/D', $grant['metric_id']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $grant['metric_version']) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $grant['project_uuid']) !== 1
            || preg_match('/^[A-Za-z0-9 _.,:-]{12,160}$/D', $grant['purpose']) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/D', $grant['source_owner']) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $grant['unit']) !== 1) {
            return null;
        }
        return $grant;
    }

    /**
     * File 23's current validator strips quality/freshness fields. Therefore
     * expose a value ONLY while the governed CF-05 response is green/current,
     * exact-window, non-suppressed and finite. Otherwise omit, never force 0.
     *
     * @param array<string,mixed> $result
     * @param array<string,string> $grant
     * @return array<string,mixed>|null
     */
    private static function presentApprovedAggregate(array $result, array $grant, \DateTimeImmutable $from, \DateTimeImmutable $end): ?array
    {
        $value = $result['value'] ?? null;
        $cohort = $result['cohort_size'] ?? null;
        $minimum = $result['minimum_cohort'] ?? null;
        $window = $result['window'] ?? null;
        if (($result['metric_id'] ?? null) !== $grant['metric_id']
            || ($result['metric_version'] ?? null) !== $grant['metric_version']
            || ($result['source_owner'] ?? null) !== $grant['source_owner']
            || ($result['quality_status'] ?? null) !== 'green'
            || ($result['status'] ?? null) !== 'current_within_declared_quality'
            || !is_array($window)
            || ($window['start'] ?? null) !== $from->format('c')
            || ($window['end'] ?? null) !== $end->format('c')
            || (!is_float($value) && !is_int($value))
            || !is_finite((float) $value)
            || !is_int($cohort) || !is_int($minimum)
            || $cohort < $minimum || $minimum < 20
            || !is_string($result['business_question'] ?? null)
            || trim($result['business_question']) === ''
            || !is_string($result['data_through'] ?? null)) {
            return null;
        }
        $through = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $result['data_through']);
        if ($through === false || $through->getTimestamp() > time() + 300) {
            return null;
        }

        $definition = substr(wp_strip_all_tags($result['business_question']), 0, 240);
        $definition .= ' | Source: ' . $grant['source_owner']
            . '; metric ' . $grant['metric_id'] . '@' . $grant['metric_version']
            . '; window ' . $from->format('Y-m-d') . ' to ' . $end->modify('-1 day')->format('Y-m-d')
            . '; data through ' . $through->format('Y-m-d H:i') . ' UTC';
        return [
            'metric_key' => $grant['metric_id'],
            'label' => substr(wp_strip_all_tags((string) ($result['name'] ?? $grant['metric_id'])), 0, 160),
            'definition' => substr($definition, 0, 500),
            'value' => $value,
            'unit' => $grant['unit'],
            'interval' => 'custom',
            'cohort_count' => $cohort,
            'privacy_threshold' => $minimum,
            'generated_at' => gmdate('c'),
        ];
    }
}
