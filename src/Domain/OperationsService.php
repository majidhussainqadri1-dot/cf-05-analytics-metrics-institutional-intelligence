<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;

final class OperationsService
{
    public function __construct(private Database $database)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function publishSnapshot(array $input, int $actor): array|WP_Error
    {
        foreach (['metric_id','metric_version','window_start','window_end','dimensions','cohort_size','quality_status','data_through'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing snapshot field: ' . $field, ['status' => 400]);
            }
        }
        $metric = $this->activeMetric((string) $input['metric_id'], (string) $input['metric_version']);
        if (!is_array($metric)) {
            return new WP_Error('metric_not_active', 'Exact metric version is not active.', ['status' => 409]);
        }
        $definition = json_decode((string) $metric['definition_json'], true);
        $allowed = is_array($definition['dimensions'] ?? null) ? array_map('strval', $definition['dimensions']) : [];
        $dimensions = is_array($input['dimensions']) ? $input['dimensions'] : [];
        foreach (array_keys($dimensions) as $dimension) {
            if (!in_array((string) $dimension, $allowed, true)) {
                return new WP_Error('dimension_not_allowed', 'Unapproved metric dimension.', ['status' => 422]);
            }
        }
        $cohort = max(0, (int) $input['cohort_size']);
        $minimum = max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20));
        if ($cohort < $minimum) {
            return new WP_Error('cohort_suppressed', 'Cohort is below the approved privacy threshold.', ['status' => 403]);
        }
        $start = $this->date((string) $input['window_start']);
        $end = $this->date((string) $input['window_end']);
        $through = $this->date((string) $input['data_through']);
        if ($start === null || $end === null || $through === null || $start >= $end) {
            return new WP_Error('invalid_window', 'Invalid snapshot time window.', ['status' => 400]);
        }
        ksort($dimensions);
        $dimensionsJson = wp_json_encode($dimensions, JSON_UNESCAPED_SLASHES);
        $manifest = [
            'metric_id' => (string) $input['metric_id'],
            'metric_version' => (string) $input['metric_version'],
            'window_start' => $start,
            'window_end' => $end,
            'dimensions' => $dimensions,
            'value' => $input['value'] ?? null,
            'numerator' => $input['numerator'] ?? null,
            'denominator' => $input['denominator'] ?? null,
            'cohort_size' => $cohort,
            'quality_status' => sanitize_key((string) $input['quality_status']),
            'data_through' => $through,
            'caveats' => array_values(array_map('sanitize_text_field', (array) ($input['caveats'] ?? []))),
        ];
        global $wpdb;
        $ok = $wpdb->replace($this->database->table('metric_snapshots'), [
            'metric_id' => $manifest['metric_id'],
            'metric_version' => $manifest['metric_version'],
            'window_start' => $start,
            'window_end' => $end,
            'dimensions_hash' => hash('sha256', $dimensionsJson),
            'dimensions_json' => $dimensionsJson,
            'value_decimal' => is_numeric($manifest['value']) ? (string) $manifest['value'] : null,
            'numerator_decimal' => is_numeric($manifest['numerator']) ? (string) $manifest['numerator'] : null,
            'denominator_decimal' => is_numeric($manifest['denominator']) ? (string) $manifest['denominator'] : null,
            'cohort_size' => $cohort,
            'quality_status' => $manifest['quality_status'],
            'data_through' => $through,
            'caveats_json' => wp_json_encode($manifest['caveats']),
            'snapshot_hash' => hash('sha256', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES)),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok === false) {
            return new WP_Error('snapshot_failed', 'Metric snapshot could not be stored.', ['status' => 500]);
        }
        do_action('smai_snapshot_published', $manifest, $actor);
        return ['published' => true, 'snapshot_hash' => hash('sha256', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES))];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function createScheduledReport(array $input, int $actor): array|WP_Error
    {
        foreach (['name','project_uuid','metrics','recipients','cadence','expires_at'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing report field: ' . $field, ['status' => 400]);
            }
        }
        $project = $this->activeProject((string) $input['project_uuid'], $actor);
        if (!is_array($project)) {
            return new WP_Error('project_denied', 'Active access project is required.', ['status' => 403]);
        }
        $metrics = (array) $input['metrics'];
        foreach ($metrics as $metric) {
            if (!is_array($metric) || empty($metric['metric_id']) || empty($metric['metric_version'])) {
                return new WP_Error('metric_version_required', 'Every scheduled metric must pin an exact version.', ['status' => 422]);
            }
            if (!is_array($this->activeMetric((string) $metric['metric_id'], (string) $metric['metric_version']))) {
                return new WP_Error('metric_not_active', 'Scheduled report references a non-active metric version.', ['status' => 409]);
            }
        }
        $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', (array) $input['recipients']), 'is_email')));
        if ($recipients === []) {
            return new WP_Error('no_recipients', 'At least one valid recipient is required.', ['status' => 400]);
        }
        $expires = $this->date((string) $input['expires_at']);
        if ($expires === null || strtotime($expires) <= time()) {
            return new WP_Error('invalid_expiry', 'Report expiry must be in the future.', ['status' => 400]);
        }
        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'smai_scheduled_reports', [
            'report_uuid' => $uuid,
            'name' => sanitize_text_field((string) $input['name']),
            'owner_user_id' => $actor,
            'project_uuid' => (string) $input['project_uuid'],
            'state' => 'active',
            'metrics_json' => wp_json_encode($metrics),
            'recipients_json' => wp_json_encode($recipients),
            'cadence' => sanitize_key((string) $input['cadence']),
            'secure_link_ttl' => max(300, min(DAY_IN_SECONDS * 7, (int) ($input['secure_link_ttl'] ?? DAY_IN_SECONDS))),
            'next_run_at' => $now,
            'expires_at' => $expires,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('report_create_failed', 'Scheduled report could not be stored.', ['status' => 500]);
        }
        return ['report_uuid' => $uuid, 'state' => 'active'];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function requestExport(array $input, int $actor): array|WP_Error
    {
        foreach (['project_uuid','purpose','metrics','columns','expires_at'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing export field: ' . $field, ['status' => 400]);
            }
        }
        if (!is_array($this->activeProject((string) $input['project_uuid'], $actor))) {
            return new WP_Error('project_denied', 'Active access project is required.', ['status' => 403]);
        }
        $columns = array_values(array_unique(array_map('sanitize_key', (array) $input['columns'])));
        $allow = ['metric_id','metric_version','window_start','window_end','dimensions','value','numerator','denominator','cohort_size','quality_status','data_through','caveats'];
        if ($columns === [] || array_diff($columns, $allow) !== []) {
            return new WP_Error('columns_denied', 'Export contains unapproved columns.', ['status' => 422]);
        }
        $metrics = (array) $input['metrics'];
        if ($metrics === [] || count($metrics) > 100) {
            return new WP_Error('export_scope_denied', 'Export metric scope is invalid.', ['status' => 422]);
        }
        foreach ($metrics as $metric) {
            if (!is_array($metric) || !is_array($this->activeMetric((string) ($metric['metric_id'] ?? ''), (string) ($metric['metric_version'] ?? '')))) {
                return new WP_Error('metric_denied', 'Export must use active exact metric versions.', ['status' => 409]);
            }
        }
        $expires = $this->date((string) $input['expires_at']);
        if ($expires === null || strtotime($expires) > time() + DAY_IN_SECONDS * 7 || strtotime($expires) <= time()) {
            return new WP_Error('invalid_expiry', 'Export expiry must be within seven days.', ['status' => 400]);
        }
        $uuid = wp_generate_uuid4();
        $definition = [
            'metrics' => $metrics,
            'columns' => $columns,
            'filters' => is_array($input['filters'] ?? null) ? $input['filters'] : [],
            'row_limit' => max(1, min(100000, (int) ($input['row_limit'] ?? 10000))),
            'format' => 'csv',
            'csv_formula_neutralization' => true,
            'encrypted_delivery' => true,
        ];
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($this->database->table('exports'), [
            'export_uuid' => $uuid,
            'project_uuid' => (string) $input['project_uuid'],
            'requester_user_id' => $actor,
            'state' => 'requested',
            'purpose' => sanitize_text_field((string) $input['purpose']),
            'definition_json' => wp_json_encode($definition),
            'expires_at' => $expires,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('export_failed', 'Export request could not be created.', ['status' => 500]);
        }
        return ['export_uuid' => $uuid, 'state' => 'requested', 'download_url' => null];
    }

    /** @return array<string,mixed>|WP_Error */
    public function propagateDeletion(string $sourceModule, string $sourceVersion, string $rawKey, int $actor): array|WP_Error
    {
        if ($rawKey === '' || strlen($rawKey) > 500) {
            return new WP_Error('invalid_deletion_key', 'Invalid deletion key.', ['status' => 400]);
        }
        $secret = defined('SMAI_PSEUDONYM_KEY') ? (string) SMAI_PSEUDONYM_KEY : '';
        if (strlen($secret) < 32) {
            return new WP_Error('pseudonym_key_missing', 'Deletion propagation is unavailable without the private pseudonym key.', ['status' => 503]);
        }
        $key = hash_hmac('sha256', 'deletion|' . $rawKey, $secret);
        global $wpdb;
        $eventTable = $this->database->table('events');
        $holds = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}smai_retention_holds WHERE state='active' AND scope_type='deletion_key' AND scope_ref=%s AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())", $key));
        if ((int) $holds > 0) {
            return new WP_Error('retention_hold', 'Deletion is blocked by an approved retention hold.', ['status' => 409]);
        }
        $deletedEvents = $wpdb->query($wpdb->prepare("DELETE FROM {$eventTable} WHERE deletion_key=%s", $key));
        $revokedExports = $wpdb->query($wpdb->prepare("UPDATE {$this->database->table('exports')} SET state='revoked',revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL AND definition_json LIKE %s", '%' . $wpdb->esc_like($key) . '%'));
        $result = ['events_deleted' => max(0, (int) $deletedEvents), 'exports_revoked' => max(0, (int) $revokedExports), 'recompute_required' => true];
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->replace($this->database->table('deletion_jobs'), [
            'deletion_key' => $key,
            'source_module' => sanitize_key($sourceModule),
            'source_version' => sanitize_text_field($sourceVersion),
            'state' => 'completed',
            'scope_json' => wp_json_encode(['events','exports','snapshots_recompute']),
            'result_json' => wp_json_encode($result),
            'retry_count' => 0,
            'requested_at' => $now,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        do_action('smai_deletion_propagated', $key, $result, $actor);
        return $result;
    }

    /** @return array<string,mixed> */
    public function runRetention(): array
    {
        global $wpdb;
        $rawDays = max(1, min(365, (int) get_option('smai_raw_retention_days', 30)));
        $quarantineDays = max(1, min(90, (int) get_option('smai_quarantine_retention_days', 14)));
        $events = $wpdb->query($wpdb->prepare("DELETE FROM {$this->database->table('events')} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND (deletion_key IS NULL OR deletion_key NOT IN (SELECT scope_ref FROM {$wpdb->prefix}smai_retention_holds WHERE state='active' AND scope_type='deletion_key' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())))", $rawDays));
        $quarantine = $wpdb->query($wpdb->prepare("DELETE FROM {$this->database->table('quarantine')} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND status IN ('closed','discarded')", $quarantineDays));
        $exports = $wpdb->query("UPDATE {$this->database->table('exports')} SET state='expired',updated_at=UTC_TIMESTAMP() WHERE state IN ('requested','building','ready') AND expires_at<=UTC_TIMESTAMP()");
        $projects = $wpdb->query("UPDATE {$this->database->table('access_projects')} SET state='expired',revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),row_version=row_version+1 WHERE state='active' AND expires_at<=UTC_TIMESTAMP()");
        return ['events' => max(0, (int) $events), 'quarantine' => max(0, (int) $quarantine), 'exports_expired' => max(0, (int) $exports), 'projects_expired' => max(0, (int) $projects)];
    }

    /** @return array<string,mixed>|null */
    private function activeMetric(string $id, string $version): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->database->table('metrics')} WHERE metric_id=%s AND metric_version=%s AND state='active'", $id, $version), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function activeProject(string $uuid, int $actor): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->database->table('access_projects')} WHERE project_uuid=%s AND owner_user_id=%d AND state='active' AND expires_at>UTC_TIMESTAMP()", $uuid, $actor), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function date(string $value): ?string
    {
        $time = strtotime($value);
        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
