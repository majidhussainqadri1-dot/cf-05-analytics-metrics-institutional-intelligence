<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Http;

use Sabri\AnalyticsIntelligence\Domain\AccessProjectService;
use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Domain\CatalogLifecycleService;
use Sabri\AnalyticsIntelligence\Domain\DashboardService;
use Sabri\AnalyticsIntelligence\Domain\DatasetCatalog;
use Sabri\AnalyticsIntelligence\Domain\DeletionService;
use Sabri\AnalyticsIntelligence\Domain\EventIngestionService;
use Sabri\AnalyticsIntelligence\Domain\EventSchemaRegistry;
use Sabri\AnalyticsIntelligence\Domain\ExperimentService;
use Sabri\AnalyticsIntelligence\Domain\ExportService;
use Sabri\AnalyticsIntelligence\Domain\LineageService;
use Sabri\AnalyticsIntelligence\Domain\MetricCatalog;
use Sabri\AnalyticsIntelligence\Domain\MetricQueryService;
use Sabri\AnalyticsIntelligence\Domain\NarrativeService;
use Sabri\AnalyticsIntelligence\Domain\ProviderService;
use Sabri\AnalyticsIntelligence\Domain\QualityService;
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Domain\RestoreService;
use Sabri\AnalyticsIntelligence\Domain\SnapshotService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\IdempotencyGuard;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeActivationService;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    private Database $db;
    private HealthService $health;

    public function __construct(Database $db, HealthService $health)
    {
        $this->db = $db;
        $this->health = $health;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_pre_serve_request', [$this, 'serveRawDownload'], 10, 4);
    }

    public function routes(): void
    {
        $ns = 'sabri-analytics/v1';

        $this->route($ns, '/health', WP_REST_Server::READABLE, fn(WP_REST_Request $r) => $this->response($this->health->report(current_user_can('smai_manage_quality'))), 'smai_view_insights');
        $this->route($ns, '/runtime/activation/propose', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('runtime-activation-propose', $r, fn(array $p) => (new RuntimeActivationService($this->db))->propose((string) ($p['target_state'] ?? ''), strtolower((string) ($p['evidence_hash'] ?? '')), (string) ($p['reason'] ?? ''), get_current_user_id()), 201), 'smai_activate_runtime');
        $this->route($ns, '/runtime/activation/approve', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('runtime-activation-approve', $r, fn(array $p) => (new RuntimeActivationService($this->db))->approve(strtolower((string) ($p['request_hash'] ?? '')), get_current_user_id()), 200), 'smai_activate_runtime');
        $this->route($ns, '/runtime/disable', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('runtime-disable', $r, fn(array $p) => (new RuntimeActivationService($this->db))->disable((string) ($p['reason'] ?? ''), get_current_user_id(), (bool) ($p['foundation_disabled'] ?? false)), 200), 'smai_activate_runtime');

        $this->route($ns, '/events', WP_REST_Server::CREATABLE, [$this, 'ingestEvent'], true);
        $this->route($ns, '/experiments/assignments', WP_REST_Server::CREATABLE, [$this, 'recordAssignment'], true);

        $this->route($ns, '/catalog/events', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('event-schema-register', $r, fn(array $p) => (new EventSchemaRegistry($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_catalog');
        $this->route($ns, '/catalog/metrics', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('metric-register', $r, fn(array $p) => (new MetricCatalog($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_catalog');
        $this->route($ns, '/catalog/datasets', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('dataset-register', $r, fn(array $p) => (new DatasetCatalog($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_catalog');
        $this->route($ns, '/catalog/(?P<object>events|metrics|datasets)/(?P<id>\d+)/transition', WP_REST_Server::EDITABLE, [$this, 'transitionCatalog'], 'smai_approve_catalog');

        $this->route($ns, '/quality/rules', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('quality-rule-register', $r, fn(array $p) => (new QualityService($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_quality');
        $this->route($ns, '/quality/rules/(?P<rule>[a-z][a-z0-9_.-]{2,189})/(?P<version>[0-9]+\.[0-9]+\.[0-9]+)/activate', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('quality-rule-activate', $r, fn(array $p) => (new QualityService($this->db))->activate((string) $r['rule'], (string) $r['version'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_approve_catalog');
        $this->route($ns, '/quality/run', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('quality-run', $r, fn(array $p) => (new QualityService($this->db))->enqueue((string) ($p['dataset_ref'] ?? ''), get_current_user_id(), isset($p['build_uuid']) ? (string) $p['build_uuid'] : null), 202), 'smai_manage_quality');

        $this->route($ns, '/backfills', WP_REST_Server::CREATABLE, [$this, 'planBackfill'], 'smai_manage_backfills');
        $this->route($ns, '/backfills/(?P<uuid>[0-9a-fA-F-]{36})/dry-run', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('backfill-dry-run', $r, fn(array $p) => (new BackfillService($this->db))->dryRun((string) $r['uuid'], get_current_user_id()), 200), 'smai_manage_backfills');
        $this->route($ns, '/backfills/(?P<uuid>[0-9a-fA-F-]{36})/approve', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('backfill-approve', $r, fn(array $p) => (new BackfillService($this->db))->approveAndQueue((string) $r['uuid'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 202), 'smai_approve_catalog');
        $this->route($ns, '/backfills/(?P<uuid>[0-9a-fA-F-]{36})/activate', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('backfill-activate', $r, fn(array $p) => (new BackfillService($this->db))->activate((string) $r['uuid'], get_current_user_id()), 200), 'smai_approve_catalog');
        $this->route($ns, '/backfills/(?P<uuid>[0-9a-fA-F-]{36})/rollback', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('backfill-rollback', $r, fn(array $p) => (new BackfillService($this->db))->rollback((string) $r['uuid'], get_current_user_id()), 200), 'smai_restore');

        $this->route($ns, '/snapshots', WP_REST_Server::CREATABLE, [$this, 'enqueueSnapshot'], 'smai_manage_quality');
        $this->route($ns, '/metrics/(?P<metric_id>[a-z][a-z0-9_.-]{2,189})', WP_REST_Server::READABLE, [$this, 'queryMetric'], 'smai_query_metrics');

        $this->route($ns, '/access/projects', WP_REST_Server::CREATABLE, [$this, 'requestAccess'], 'smai_manage_access');
        $this->route($ns, '/access/projects/(?P<uuid>[0-9a-fA-F-]{36})/approve', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('access-approve', $r, fn(array $p) => (new AccessProjectService($this->db))->approve((string) $r['uuid'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_manage_access');
        $this->route($ns, '/access/projects/(?P<uuid>[0-9a-fA-F-]{36})/revoke', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('access-revoke', $r, fn(array $p) => (new AccessProjectService($this->db))->revoke((string) $r['uuid'], (string) ($p['reason'] ?? ''), get_current_user_id()), 200), 'smai_manage_access');

        $this->route($ns, '/exports', WP_REST_Server::CREATABLE, [$this, 'requestExport'], 'smai_export_metrics');
        $this->route($ns, '/exports/(?P<uuid>[0-9a-fA-F-]{36})', WP_REST_Server::READABLE, [$this, 'exportStatus'], 'smai_export_metrics');
        $this->route($ns, '/exports/(?P<uuid>[0-9a-fA-F-]{36})/download', WP_REST_Server::READABLE, [$this, 'downloadExport'], 'smai_export_metrics');

        $this->route($ns, '/reports', WP_REST_Server::CREATABLE, [$this, 'createReport'], 'smai_manage_reports');
        $this->route($ns, '/reports/(?P<uuid>[0-9a-fA-F-]{36})/activate', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('report-activate', $r, fn(array $p) => (new ReportService($this->db))->activate((string) $r['uuid'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_approve_catalog');
        $this->route($ns, '/report-deliveries/(?P<uuid>[0-9a-fA-F-]{36})', WP_REST_Server::READABLE, [$this, 'accessReportDelivery'], fn() => is_user_logged_in());

        $this->route($ns, '/narratives', WP_REST_Server::CREATABLE, [$this, 'createNarrative'], 'smai_manage_reports');
        $this->route($ns, '/narratives/(?P<uuid>[0-9a-fA-F-]{36})/publish', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('narrative-publish', $r, fn(array $p) => (new NarrativeService($this->db))->publish((string) $r['uuid'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_approve_catalog');

        $this->route($ns, '/experiments', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('experiment-create', $r, fn(array $p) => (new ExperimentService($this->db))->create($p, get_current_user_id()), 201), 'smai_manage_experiments');
        $this->route($ns, '/experiments/(?P<uuid>[0-9a-fA-F-]{36})/transition', WP_REST_Server::CREATABLE, [$this, 'transitionExperiment'], 'smai_manage_experiments');
        $this->route($ns, '/experiments/(?P<uuid>[0-9a-fA-F-]{36})/analyze', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('experiment-analyze', $r, fn(array $p) => (new ExperimentService($this->db))->analyze((string) $r['uuid'], (string) ($p['analysis_version'] ?? ''), get_current_user_id()), 201), 'smai_manage_experiments');
        $this->route($ns, '/analyses/(?P<uuid>[0-9a-fA-F-]{36})/publish', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('analysis-publish', $r, fn(array $p) => (new ExperimentService($this->db))->publishAnalysis((string) $r['uuid'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_approve_catalog');
        $this->route($ns, '/decisions', WP_REST_Server::CREATABLE, [$this, 'recordDecision'], 'smai_manage_experiments');
        $this->route($ns, '/decisions/(?P<uuid>[0-9a-fA-F-]{36})/outcome', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('decision-outcome', $r, fn(array $p) => (new ExperimentService($this->db))->recordOutcome((string) $r['uuid'], is_array($p['outcome'] ?? null) ? $p['outcome'] : [], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_manage_experiments');

        $this->route($ns, '/providers', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('provider-register', $r, fn(array $p) => (new ProviderService($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_providers');
        $this->route($ns, '/providers/(?P<provider>[a-z0-9][a-z0-9_.-]{1,99})/(?P<version>[0-9]+\.[0-9]+\.[0-9]+)/transition', WP_REST_Server::CREATABLE, [$this, 'transitionProvider'], 'smai_manage_providers');
        $this->route($ns, '/providers/(?P<provider>[a-z0-9][a-z0-9_.-]{1,99})/exit', WP_REST_Server::READABLE, fn(WP_REST_Request $r) => $this->response((new ProviderService($this->db))->exitStatus((string) $r['provider'])), 'smai_manage_providers');

        $this->route($ns, '/deletions', WP_REST_Server::CREATABLE, [$this, 'requestDeletion'], 'smai_manage_deletions');
        $this->route($ns, '/deletions/(?P<uuid>[0-9a-fA-F-]{36})', WP_REST_Server::READABLE, [$this, 'deletionStatus'], 'smai_manage_deletions');

        $this->route($ns, '/restore-points', WP_REST_Server::CREATABLE, [$this, 'recordRestore'], 'smai_restore');
        $this->route($ns, '/restore-points/(?P<uuid>[0-9a-fA-F-]{36})/verify', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('restore-verify', $r, fn(array $p) => (new RestoreService($this->db))->verify((string) $r['uuid'], get_current_user_id()), 200), 'smai_restore');

        $this->route($ns, '/dashboards', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('dashboard-register', $r, fn(array $p) => (new DashboardService($this->db))->register($p, get_current_user_id()), 201), 'smai_manage_reports');
        $this->route($ns, '/dashboards/(?P<dashboard>[a-z][a-z0-9_.-]{2,189})/(?P<version>[0-9]+\.[0-9]+\.[0-9]+)/activate', WP_REST_Server::CREATABLE, fn(WP_REST_Request $r) => $this->mutation('dashboard-activate', $r, fn(array $p) => (new DashboardService($this->db))->activate((string) $r['dashboard'], (string) $r['version'], (int) ($p['row_version'] ?? 0), get_current_user_id()), 200), 'smai_approve_catalog');
        $this->route($ns, '/dashboards/(?P<dashboard>[a-z][a-z0-9_.-]{2,189})/(?P<version>[0-9]+\.[0-9]+\.[0-9]+)', WP_REST_Server::READABLE, fn(WP_REST_Request $r) => $this->response((new DashboardService($this->db))->bundle((string) $r['dashboard'], (string) $r['version'], get_current_user_id())), 'smai_view_insights');

        $this->route($ns, '/lineage/(?P<type>[a-z]+)/(?P<ref>[a-zA-Z0-9_.@:-]{1,190})', WP_REST_Server::READABLE, fn(WP_REST_Request $r) => $this->response(['edges' => (new LineageService($this->db))->upstream((string) $r['type'], (string) $r['ref'])]), 'smai_audit');
    }

    public function ingestEvent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $service = (new ServiceAuthenticator($this->db))->authenticate($request);
        if (is_wp_error($service)) {
            return $service;
        }
        $payload = $this->payload($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $result = (new EventIngestionService($this->db))->ingest($payload, $service);
        return is_wp_error($result) ? $result : $this->response($result, in_array((string) ($result['status'] ?? ''), ['accepted','accepted_pipeline_pending'], true) ? 202 : 200);
    }

    public function recordAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $service = (new ServiceAuthenticator($this->db))->authenticate($request);
        if (is_wp_error($service)) {
            return $service;
        }
        $payload = $this->payload($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $result = (new ExperimentService($this->db))->recordAssignment($payload, $service);
        return is_wp_error($result) ? $result : $this->response($result, in_array((string) ($result['status'] ?? ''), ['accepted','accepted_pipeline_pending'], true) ? 202 : 200);
    }

    public function transitionCatalog(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $map = ['events' => 'event_schema', 'metrics' => 'metric', 'datasets' => 'dataset'];
        return $this->mutation('catalog-transition', $request, function (array $payload) use ($request, $map) {
            return (new CatalogLifecycleService($this->db))->transition(
                $map[(string) $request['object']] ?? '',
                (int) $request['id'],
                sanitize_key((string) ($payload['target_state'] ?? '')),
                (int) ($payload['row_version'] ?? 0),
                (string) ($payload['reason'] ?? ''),
                get_current_user_id(),
                (string) ($payload['transition_idempotency_key'] ?? '')
            );
        }, 200);
    }

    public function planBackfill(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('backfill-plan', $request, function (array $p) {
            return (new BackfillService($this->db))->plan(
                (string) ($p['dataset_id'] ?? ''),
                (string) ($p['dataset_version'] ?? ''),
                (string) ($p['date_start'] ?? ''),
                (string) ($p['date_end'] ?? ''),
                is_array($p['definition'] ?? null) ? $p['definition'] : [],
                get_current_user_id()
            );
        }, 201);
    }

    public function enqueueSnapshot(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('snapshot-enqueue', $request, function (array $p) {
            return (new SnapshotService($this->db))->enqueue(
                (string) ($p['metric_id'] ?? ''),
                (string) ($p['metric_version'] ?? ''),
                (string) ($p['window_start'] ?? ''),
                (string) ($p['window_end'] ?? ''),
                is_array($p['dimensions'] ?? null) ? $p['dimensions'] : [],
                get_current_user_id()
            );
        }, 202);
    }

    public function queryMetric(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $dimensions = $request->get_param('dimensions');
        if (is_string($dimensions) && $dimensions !== '') {
            $dimensions = json_decode($dimensions, true);
        }
        $clean = [];
        foreach (array_slice(is_array($dimensions) ? $dimensions : [], 0, 10, true) as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) === 1 && is_scalar($value)) {
                $clean[$key] = is_string($value) ? Text::truncate(sanitize_text_field($value), 100) : $value;
            }
        }
        $result = (new MetricQueryService($this->db))->query(
            (string) $request['metric_id'],
            (string) $request->get_param('version'),
            (string) $request->get_param('window_start'),
            (string) $request->get_param('window_end'),
            $clean,
            get_current_user_id(),
            Text::truncate((string) $request->get_param('purpose'), 190),
            (string) $request->get_param('project_uuid')
        );
        return is_wp_error($result) ? $result : $this->response($result);
    }

    public function requestAccess(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('access-request', $request, function (array $p) {
            return (new AccessProjectService($this->db))->request(
                (string) ($p['name'] ?? ''),
                (string) ($p['purpose'] ?? ''),
                is_array($p['datasets'] ?? null) ? array_map('strval', $p['datasets']) : [],
                is_array($p['fields'] ?? null) ? $p['fields'] : [],
                (string) ($p['expires_at'] ?? ''),
                (bool) ($p['training_confirmed'] ?? false),
                get_current_user_id()
            );
        }, 201);
    }

    public function requestExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('export-request', $request, function (array $p) {
            return (new ExportService($this->db))->request(
                (string) ($p['project_uuid'] ?? ''),
                (string) ($p['purpose'] ?? ''),
                is_array($p['definition'] ?? null) ? $p['definition'] : [],
                get_current_user_id()
            );
        }, 202);
    }

    public function exportStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT export_uuid,state,file_sha256,expires_at,revoked_at,created_at,completed_at FROM `{$this->db->table('exports')}` WHERE export_uuid=%s AND requester_user_id=%d",
            (string) $request['uuid'],
            get_current_user_id()
        ), ARRAY_A);
        return is_array($row) ? $this->response($row) : new WP_Error('smai_export_not_found', 'Export was not found.', ['status' => 404]);
    }

    public function downloadExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = (new ExportService($this->db))->download(
            (string) $request['uuid'],
            (string) $request->get_param('token'),
            get_current_user_id()
        );
        if (is_wp_error($result)) {
            return $result;
        }
        return $this->response([
            '__smai_raw_download' => true,
            'content' => $result,
            'filename' => 'cf05-export-' . (string) $request['uuid'] . '.csv',
            'content_type' => 'text/csv; charset=utf-8',
        ]);
    }

    public function createReport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('report-create', $request, function (array $p) {
            return (new ReportService($this->db))->create(
                (string) ($p['name'] ?? ''),
                (string) ($p['project_uuid'] ?? ''),
                is_array($p['definition'] ?? null) ? $p['definition'] : [],
                isset($p['schedule']) ? (string) $p['schedule'] : null,
                get_current_user_id()
            );
        }, 201);
    }

    public function accessReportDelivery(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = (new ReportService($this->db))->accessDelivery(
            (string) $request['uuid'],
            (string) $request->get_param('token'),
            get_current_user_id()
        );
        return is_wp_error($result) ? $result : $this->response($result);
    }

    public function createNarrative(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('narrative-create', $request, function (array $p) {
            return (new NarrativeService($this->db))->create(
                (string) ($p['title'] ?? ''),
                (string) ($p['observation'] ?? ''),
                (string) ($p['inference'] ?? ''),
                (string) ($p['recommendation'] ?? ''),
                is_array($p['citations'] ?? null) ? $p['citations'] : [],
                (bool) ($p['ai_assisted'] ?? false),
                get_current_user_id()
            );
        }, 201);
    }

    public function transitionExperiment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('experiment-transition', $request, function (array $p) use ($request) {
            return (new ExperimentService($this->db))->transition(
                (string) $request['uuid'],
                sanitize_key((string) ($p['target_state'] ?? '')),
                (int) ($p['row_version'] ?? 0),
                (string) ($p['reason'] ?? ''),
                get_current_user_id()
            );
        }, 200);
    }

    public function recordDecision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('decision-record', $request, function (array $p) {
            return (new ExperimentService($this->db))->recordDecision(
                (string) ($p['subject_type'] ?? ''),
                (string) ($p['subject_ref'] ?? ''),
                is_array($p['record'] ?? null) ? $p['record'] : [],
                get_current_user_id()
            );
        }, 201);
    }

    public function transitionProvider(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('provider-transition', $request, function (array $p) use ($request) {
            return (new ProviderService($this->db))->transition(
                (string) $request['provider'],
                (string) $request['version'],
                sanitize_key((string) ($p['target_state'] ?? '')),
                (int) ($p['row_version'] ?? 0),
                get_current_user_id(),
                is_array($p['evidence'] ?? null) ? $p['evidence'] : []
            );
        }, 200);
    }

    public function requestDeletion(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('deletion-request', $request, function (array $p) {
            return (new DeletionService($this->db))->request(
                (string) ($p['deletion_key'] ?? ''),
                (string) ($p['source_module'] ?? ''),
                (string) ($p['source_version'] ?? ''),
                is_array($p['scope'] ?? null) ? $p['scope'] : [],
                get_current_user_id()
            );
        }, 202);
    }

    public function deletionStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT job_uuid,source_module,source_version,state,result_json,requested_at,completed_at,updated_at FROM `{$this->db->table('deletion_jobs')}` WHERE job_uuid=%s",
            (string) $request['uuid']
        ), ARRAY_A);
        if (!is_array($row)) {
            return new WP_Error('smai_deletion_not_found', 'Deletion job was not found.', ['status' => 404]);
        }
        $row['result'] = Json::object((string) ($row['result_json'] ?? '{}'));
        unset($row['result_json']);
        return $this->response($row);
    }

    public function recordRestore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->mutation('restore-record', $request, function (array $p) {
            return (new RestoreService($this->db))->record(
                (string) ($p['code_sha'] ?? ''),
                is_array($p['evidence'] ?? null) ? $p['evidence'] : [],
                get_current_user_id()
            );
        }, 201);
    }

    public function serveRawDownload(bool $served, mixed $result, WP_REST_Request $request, mixed $server): bool
    {
        if (!$result instanceof WP_REST_Response) {
            return $served;
        }
        $data = $result->get_data();
        if (!is_array($data) || ($data['__smai_raw_download'] ?? false) !== true) {
            return $served;
        }
        if (headers_sent()) {
            return $served;
        }
        header('Content-Type: ' . (string) ($data['content_type'] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name((string) ($data['filename'] ?? 'download.bin')) . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo (string) ($data['content'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return true;
    }

    /** @param callable(array<string,mixed>):(array<string,mixed>|WP_Error) $callback */
    private function mutation(string $scope, WP_REST_Request $request, callable $callback, int $status): WP_REST_Response|WP_Error
    {
        $payload = $this->payload($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $key = (string) $request->get_header('idempotency-key');
        $actor = (string) get_current_user_id();
        $guard = new IdempotencyGuard($this->db);
        $begin = $guard->begin($scope, $actor, $key, hash('sha256', Json::canonical($payload)));
        if (is_wp_error($begin)) {
            return $begin;
        }
        if ($begin['state'] === 'completed') {
            return $this->response(is_array($begin['response']) ? $begin['response'] : [], (int) ($begin['status_code'] ?? $status));
        }
        try {
            $result = $callback($payload);
        } catch (\Throwable $error) {
            $guard->release($scope, $actor, $key);
            return new WP_Error('smai_mutation_failed', 'The governed operation failed safely.', [
                'status' => 500,
                'trace_id' => wp_generate_uuid4(),
            ]);
        }
        if (is_wp_error($result)) {
            $guard->release($scope, $actor, $key);
            return $result;
        }
        if (!$guard->finish($scope, $actor, $key, $result, $status)) {
            return new WP_Error('smai_idempotency_finalize_failed', 'The operation completed but its idempotency evidence could not be finalized.', ['status' => 500]);
        }
        return $this->response($result, $status);
    }

    /** @return array<string,mixed>|WP_Error */
    private function payload(WP_REST_Request $request): array|WP_Error
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status' => 400]);
        }
        if (strlen((string) $request->get_body()) > 1024 * 1024) {
            return new WP_Error('smai_request_too_large', 'Request exceeds the maximum size.', ['status' => 413]);
        }
        return $payload;
    }

    private function response(array $data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Sabri-Trace-ID', wp_generate_uuid4());
        return $response;
    }

    private function route(string $namespace, string $route, string|array $methods, callable $callback, string|bool|callable $permission): void
    {
        $permissionCallback = match (true) {
            is_string($permission) => static fn(): bool => current_user_can($permission),
            is_bool($permission) => static fn(): bool => $permission,
            default => $permission,
        };
        register_rest_route($namespace, $route, [
            'methods' => $methods,
            'callback' => $callback,
            'permission_callback' => $permissionCallback,
        ]);
    }
}
