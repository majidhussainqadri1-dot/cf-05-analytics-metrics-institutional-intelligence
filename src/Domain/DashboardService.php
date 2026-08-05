<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;

final class DashboardService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function register(array $definition, int $actorUserId): array|WP_Error
    {
        foreach (['dashboard_id','dashboard_version','name','project_uuid','audience','widgets'] as $key) {
            if (!array_key_exists($key, $definition)) {
                return new WP_Error('smai_invalid_dashboard', 'Dashboard definition is incomplete.', ['status' => 400, 'field' => $key]);
            }
        }
        if ($actorUserId < 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $definition['dashboard_id']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $definition['dashboard_version']) !== 1
            || strlen(trim((string) $definition['name'])) < 3
            || !is_array($definition['audience'])
            || !is_array($definition['widgets'])
            || $definition['widgets'] === []
            || count($definition['widgets']) > 100) {
            return new WP_Error('smai_invalid_dashboard', 'Dashboard validation failed.', ['status' => 400]);
        }
        $project = (new AccessProjectService($this->db))->get((string) $definition['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || (int) $project['owner_user_id'] !== $actorUserId || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_dashboard_project_inactive', 'Dashboard access project is inactive.', ['status' => 403]);
        }
        $audience = $this->normalizeAudience($definition['audience']);
        if (is_wp_error($audience)) {
            return $audience;
        }
        $widgets = [];
        $keys = [];
        $access = new AccessProjectService($this->db);
        foreach (array_values($definition['widgets']) as $position => $widget) {
            if (!is_array($widget)) {
                return new WP_Error('smai_invalid_dashboard_widget', 'Dashboard widget must be an object.', ['status' => 400]);
            }
            $key = (string) ($widget['key'] ?? 'widget-' . $position);
            $metricId = (string) ($widget['metric_id'] ?? '');
            $metricVersion = (string) ($widget['metric_version'] ?? '');
            $dimensions = is_array($widget['dimensions'] ?? null) ? $widget['dimensions'] : [];
            if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $key) !== 1
                || isset($keys[$key])
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $metricVersion) !== 1) {
                return new WP_Error('smai_invalid_dashboard_widget', 'Dashboard widget identity is invalid or duplicated.', ['status' => 400]);
            }
            $metric = (new MetricCatalog($this->db))->active($metricId, $metricVersion);
            if ($metric === null || !$access->authorize((string) $definition['project_uuid'], $actorUserId, 'metric:' . $metricId . '@' . $metricVersion, [])) {
                return new WP_Error('smai_dashboard_metric_denied', 'Dashboard metric is inactive or unauthorized.', ['status' => 403]);
            }
            $allowedDimensions = array_map('strval', (array) (($metric['definition']['dimensions'] ?? [])));
            foreach (array_keys($dimensions) as $dimension) {
                if (!in_array((string) $dimension, $allowedDimensions, true) || !is_scalar($dimensions[$dimension]) && $dimensions[$dimension] !== null) {
                    return new WP_Error('smai_dashboard_dimension_denied', 'Dashboard dimension is not approved.', ['status' => 400]);
                }
            }
            ksort($dimensions);
            $widget['key'] = $key;
            $widget['metric_id'] = $metricId;
            $widget['metric_version'] = $metricVersion;
            $widget['dimensions'] = $dimensions;
            $widgets[] = $widget;
            $keys[$key] = true;
        }
        $expiresAt = null;
        if (!empty($definition['expires_at'])) {
            $expiry = strtotime((string) $definition['expires_at']);
            if ($expiry === false || $expiry <= time() || $expiry > strtotime((string) $project['expires_at'])) {
                return new WP_Error('smai_invalid_dashboard_expiry', 'Dashboard expiry is invalid or exceeds project expiry.', ['status' => 400]);
            }
            $expiresAt = gmdate('Y-m-d H:i:s', $expiry);
        }
        $definition['audience'] = $audience;
        $definition['widgets'] = $widgets;
        $canonical = Json::canonical($definition);
        $hash = hash('sha256', $canonical);
        $table = $this->db->table('dashboard_definitions');
        $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT id,definition_hash,state FROM `{$table}` WHERE dashboard_id=%s AND dashboard_version=%s",
            $definition['dashboard_id'],
            $definition['dashboard_version']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['definition_hash'], $hash)) {
                return ['id' => (int) $existing['id'], 'state' => (string) $existing['state'], 'unchanged' => true];
            }
            return new WP_Error('smai_dashboard_immutable', 'Existing dashboard version is immutable.', ['status' => 409]);
        }
        $wpdb = $this->db->wpdb();
        $now = $this->db->now();
        $wpdb->query('START TRANSACTION');
        try {
            $ok = $wpdb->insert($table, [
                'dashboard_id' => $definition['dashboard_id'],
                'dashboard_version' => $definition['dashboard_version'],
                'name' => Text::truncate(trim(wp_strip_all_tags((string) $definition['name'])), 190),
                'state' => 'draft',
                'owner_user_id' => $actorUserId,
                'approved_by' => null,
                'project_uuid' => $definition['project_uuid'],
                'audience_json' => Json::canonical($audience),
                'definition_json' => $canonical,
                'definition_hash' => $hash,
                'expires_at' => $expiresAt,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ok !== 1) {
                throw new \RuntimeException('Dashboard could not be stored.');
            }
            $id = (int) $wpdb->insert_id;
            foreach ($widgets as $position => $widget) {
                if ($wpdb->insert($this->db->table('dashboard_widgets'), [
                    'dashboard_id' => $definition['dashboard_id'],
                    'dashboard_version' => $definition['dashboard_version'],
                    'widget_key' => $widget['key'],
                    'metric_id' => $widget['metric_id'],
                    'metric_version' => $widget['metric_version'],
                    'config_json' => Json::canonical($widget),
                    'position_order' => (int) $position,
                    'created_at' => $now,
                ]) !== 1) {
                    throw new \RuntimeException('Dashboard widget could not be stored.');
                }
            }
            $wpdb->query('COMMIT');
            $this->audit->log('dashboard_registered', 'dashboard', $definition['dashboard_id'] . '@' . $definition['dashboard_version'], 'success', ['project_uuid' => $definition['project_uuid'], 'definition_hash' => $hash], 'institutional_reporting', null, $actorUserId);
            return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_dashboard_store_failed', 'Dashboard could not be stored.', ['status' => 500]);
        }
    }

    public function activate(string $dashboardId, string $version, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('dashboard_definitions');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE dashboard_id=%s AND dashboard_version=%s", $dashboardId, $version), ARRAY_A);
        if (!is_array($row) || (string) $row['state'] !== 'draft' || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_dashboard_stale', 'Dashboard is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $row['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Dashboard owner cannot approve their own dashboard.', ['status' => 403]);
        }
        $project = (new AccessProjectService($this->db))->get((string) $row['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_dashboard_project_inactive', 'Dashboard access project is inactive.', ['status' => 409]);
        }
        $updated = $this->db->wpdb()->update($table, [
            'state' => 'active',
            'approved_by' => $actorUserId,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_dashboard_conflict', 'Dashboard changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('dashboard_activated', 'dashboard', $dashboardId . '@' . $version, 'success', [], 'institutional_reporting', null, $actorUserId);
        return ['dashboard_id' => $dashboardId, 'dashboard_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];
    }

    /** @return array<string,mixed>|WP_Error */
    public function bundle(string $dashboardId, string $version, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('dashboard_definitions');
        $dashboard = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE dashboard_id=%s AND dashboard_version=%s AND state='active'",
            $dashboardId,
            $version
        ), ARRAY_A);
        if (!is_array($dashboard) || ($dashboard['expires_at'] !== null && strtotime((string) $dashboard['expires_at']) <= time())) {
            return new WP_Error('smai_dashboard_not_found', 'Dashboard is unavailable.', ['status' => 404]);
        }
        $audience = Json::object((string) $dashboard['audience_json']);
        if (!$this->audienceAllows($audience, $actorUserId)) {
            return new WP_Error('smai_dashboard_access_denied', 'Dashboard access is denied.', ['status' => 403]);
        }
        $project = (new AccessProjectService($this->db))->get((string) $dashboard['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_dashboard_project_inactive', 'Dashboard access project is inactive.', ['status' => 403]);
        }
        $widgets = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('dashboard_widgets')}` WHERE dashboard_id=%s AND dashboard_version=%s ORDER BY position_order,id",
            $dashboardId,
            $version
        ), ARRAY_A);
        $out = ['dashboard_id' => $dashboardId, 'dashboard_version' => $version, 'name' => $dashboard['name'], 'generated_at' => gmdate('c'), 'widgets' => []];
        foreach (is_array($widgets) ? $widgets : [] as $widget) {
            $config = Json::object((string) $widget['config_json']);
            $dimensions = is_array($config['dimensions'] ?? null) ? $config['dimensions'] : [];
            ksort($dimensions);
            $metricId = (string) $widget['metric_id'];
            $metricVersion = (string) $widget['metric_version'];
            $authorized = (new AccessProjectService($this->db))->authorize(
                (string) $dashboard['project_uuid'],
                (int) $dashboard['owner_user_id'],
                'metric:' . $metricId . '@' . $metricVersion,
                [],
                (string) $project['purpose']
            );
            $row = $authorized && (new MetricCatalog($this->db))->active($metricId, $metricVersion) !== null
                ? $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
                    "SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1",
                    $metricId,
                    $metricVersion,
                    hash('sha256', Json::canonical($dimensions))
                ), ARRAY_A)
                : null;
            $suppressed = is_array($row) && (string) $row['quality_status'] === 'suppressed';
            $out['widgets'][] = [
                'key' => $widget['widget_key'],
                'label' => Text::truncate(wp_strip_all_tags((string) ($config['label'] ?? $widget['widget_key'])), 190),
                'metric_id' => $widget['metric_id'],
                'metric_version' => $widget['metric_version'],
                'status' => is_array($row) ? $row['quality_status'] : 'unavailable',
                'value' => is_array($row) && !$suppressed && $row['value_decimal'] !== null ? (float) $row['value_decimal'] : null,
                'window_start' => is_array($row) ? $row['window_start'] : null,
                'window_end' => is_array($row) ? $row['window_end'] : null,
                'data_through' => is_array($row) ? $row['data_through'] : null,
                'cohort_size' => is_array($row) && !$suppressed ? (int) $row['cohort_size'] : null,
                'uncertainty' => is_array($row) ? Json::object((string) ($row['uncertainty_json'] ?? '{}')) : [],
                'caveats' => is_array($row) ? Json::list((string) ($row['caveats_json'] ?? '[]')) : ['No approved snapshot is available.'],
            ];
        }
        $this->audit->log('dashboard_viewed', 'dashboard', $dashboardId . '@' . $version, 'success', ['widget_count' => count($out['widgets'])], 'institutional_reporting', null, $actorUserId);
        return $out;
    }

    /** @param array<string,mixed> $audience @return array<string,mixed>|WP_Error */
    private function normalizeAudience(array $audience): array|WP_Error
    {
        $caps = array_values(array_unique(array_filter(array_map('strval', (array) ($audience['capabilities'] ?? [])), static fn(string $cap): bool => preg_match('/^[a-z][a-z0-9_]{2,99}$/', $cap) === 1)));
        $users = [];
        foreach ((array) ($audience['user_ids'] ?? []) as $userId) {
            if ((int) $userId < 1) {
                return new WP_Error('smai_invalid_dashboard_audience', 'Dashboard audience contains an invalid user.', ['status' => 400]);
            }
            $users[(int) $userId] = (int) $userId;
        }
        if ($caps === [] && $users === []) {
            return new WP_Error('smai_invalid_dashboard_audience', 'Dashboard audience must specify capabilities or users.', ['status' => 400]);
        }
        return ['capabilities' => $caps, 'user_ids' => array_values($users)];
    }

    /** @param array<string,mixed> $audience */
    private function audienceAllows(array $audience, int $actorUserId): bool
    {
        if ($actorUserId < 1) {
            return false;
        }
        if (in_array($actorUserId, array_map('intval', (array) ($audience['user_ids'] ?? [])), true)) {
            return true;
        }
        foreach (array_map('strval', (array) ($audience['capabilities'] ?? [])) as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }
}
