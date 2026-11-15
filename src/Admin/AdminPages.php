<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Admin;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditVerifier;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\RepairService;
use Sabri\AnalyticsIntelligence\Infrastructure\RuntimeGate;

final class AdminPages
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
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_smai_safe_repair', [$this, 'safeRepair']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Institutional Insights', 'sabri-analytics-institutional-intelligence'),
            __('Insights', 'sabri-analytics-institutional-intelligence'),
            'smai_view_insights',
            'smai-insights',
            [$this, 'insights'],
            'dashicons-chart-area',
            31
        );
        $items = [
            ['smai-catalog', __('Catalog', 'sabri-analytics-institutional-intelligence'), 'smai_manage_catalog', 'catalog'],
            ['smai-quality', __('Quality', 'sabri-analytics-institutional-intelligence'), 'smai_manage_quality', 'quality'],
            ['smai-pipelines', __('Pipelines', 'sabri-analytics-institutional-intelligence'), 'smai_manage_backfills', 'pipelines'],
            ['smai-access', __('Access & Exports', 'sabri-analytics-institutional-intelligence'), 'smai_manage_access', 'access'],
            ['smai-reports', __('Reports', 'sabri-analytics-institutional-intelligence'), 'smai_manage_reports', 'reports'],
            ['smai-experiments', __('Experiments', 'sabri-analytics-institutional-intelligence'), 'smai_manage_experiments', 'experiments'],
            ['smai-system', __('System & Audit', 'sabri-analytics-institutional-intelligence'), 'smai_audit', 'system'],
        ];
        foreach ($items as [$slug, $label, $capability, $callback]) {
            add_submenu_page('smai-insights', $label, $label, $capability, $slug, [$this, $callback]);
        }
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'smai-')) {
            return;
        }
        wp_enqueue_style('smai-admin', SMAI_URL . 'assets/css/admin.css', [], SMAI_VERSION);
    }

    public function insights(): void
    {
        $this->requireCapability('smai_view_insights');
        $health = $this->health->report(false);
        $this->open(__('Institutional Insights', 'sabri-analytics-institutional-intelligence'), 'dashicons-chart-area');
        $this->statusNotice($health);
        echo '<div class="smai-grid">';
        $this->card(__('Runtime State', 'sabri-analytics-institutional-intelligence'), (string) $health['runtime_state'], __('Fail-closed until evidence-bound activation.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Published Datasets', 'sabri-analytics-institutional-intelligence'), $this->formatCount($this->countWhere('datasets', "state='published'")), __('Versioned derivative models only.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Active Metrics', 'sabri-analytics-institutional-intelligence'), $this->formatCount($this->countWhere('metrics', "state='active'")), __('Every result remains pinned to a metric version.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Published Snapshots', 'sabri-analytics-institutional-intelligence'), $this->formatCount($this->countWhere('metric_snapshots', "state='published'")), __('Cohort and quality controls apply.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Open Quality Issues', 'sabri-analytics-institutional-intelligence'), (string) ($health['quality']['open_issues'] ?? 0), __('High/critical issues block affected publication.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Dead-letter Jobs', 'sabri-analytics-institutional-intelligence'), (string) ($health['queue']['dead_letter'] ?? 0), __('No silent pipeline failure.', 'sabri-analytics-institutional-intelligence'));
        echo '</div>';
        echo '<section class="smai-panel"><h2>' . esc_html__('Constitutional boundaries', 'sabri-analytics-institutional-intelligence') . '</h2><ul>';
        foreach ([
            __('CF-05 owns approved derivative analytics governance, not native domain truth.', 'sabri-analytics-institutional-intelligence'),
            __('Raw clinical notes, prescriptions, private messages, identity evidence, credentials and payment secrets are prohibited.', 'sabri-analytics-institutional-intelligence'),
            __('Metrics inform authorized humans and native owners; analytics never executes clinical, financial, moderation, publishing or ranking decisions.', 'sabri-analytics-institutional-intelligence'),
            __('Staging, live deployment and operational acceptance remain separate evidence gates.', 'sabri-analytics-institutional-intelligence'),
        ] as $boundary) {
            echo '<li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html($boundary) . '</li>';
        }
        echo '</ul></section>';
        $this->close();
    }

    public function catalog(): void
    {
        $this->requireCapability('smai_manage_catalog');
        $this->open(__('Analytics Catalog', 'sabri-analytics-institutional-intelligence'), 'dashicons-index-card');
        $this->table(__('Event contracts', 'sabri-analytics-institutional-intelligence'), $this->rows('event_schemas', 'event_name,event_version,owner_module,state,privacy_class,retention_days,row_version,updated_at'));
        $this->table(__('Datasets and models', 'sabri-analytics-institutional-intelligence'), $this->rows('datasets', 'dataset_id,dataset_version,name,owner_module,state,privacy_class,region_code,provider_id,quality_status,row_version,updated_at'));
        $this->table(__('Metric definitions', 'sabri-analytics-institutional-intelligence'), $this->rows('metrics', 'metric_id,metric_version,name,owner_module,state,privacy_class,minimum_cohort,row_version,updated_at'));
        $this->close();
    }

    public function quality(): void
    {
        $this->requireCapability('smai_manage_quality');
        $this->open(__('Data Quality and Safety', 'sabri-analytics-institutional-intelligence'), 'dashicons-shield-alt');
        $this->statusNotice($this->health->report(false));
        $this->table(__('Open quality issues', 'sabri-analytics-institutional-intelligence'), $this->rows('quality_issues', 'issue_uuid,dataset_ref,rule_id,severity,state,summary,detected_at,updated_at', "state='open'"));
        $this->table(__('Recent quality results', 'sabri-analytics-institutional-intelligence'), $this->rows('quality_results', 'run_uuid,rule_id,rule_version,dataset_ref,build_uuid,status,observed_decimal,threshold_decimal,created_at'));
        $this->table(__('Quarantine summary', 'sabri-analytics-institutional-intelligence'), $this->groupedQuarantine());
        $this->close();
    }

    public function pipelines(): void
    {
        $this->requireCapability('smai_manage_backfills');
        $this->open(__('Pipelines, Builds and Backfills', 'sabri-analytics-institutional-intelligence'), 'dashicons-controls-repeat');
        $this->table(__('Dataset builds', 'sabri-analytics-institutional-intelligence'), $this->rows('dataset_builds', 'build_uuid,dataset_id,dataset_version,state,is_active,source_start,source_end,row_count,build_hash,updated_at'));
        $this->table(__('Backfills', 'sabri-analytics-institutional-intelligence'), $this->rows('backfills', 'backfill_uuid,dataset_id,dataset_version,state,date_start,date_end,build_uuid,row_version,created_at,updated_at'));
        $this->table(__('Background jobs', 'sabri-analytics-institutional-intelligence'), $this->rows('jobs', 'job_uuid,job_type,state,attempts,max_attempts,next_run_at,error_code,created_at,updated_at'));
        $this->table(__('Checkpoints', 'sabri-analytics-institutional-intelligence'), $this->rows('checkpoints', 'stream_ref,consumer_ref,contract_version,watermark_at,source_sequence,checkpoint_hash,updated_at'));
        $this->close();
    }

    public function access(): void
    {
        $this->requireCapability('smai_manage_access');
        $this->open(__('Access Projects and Secure Exports', 'sabri-analytics-institutional-intelligence'), 'dashicons-lock');
        $this->table(__('Access projects', 'sabri-analytics-institutional-intelligence'), $this->rows('access_projects', 'project_uuid,name,state,owner_user_id,training_confirmed,expires_at,approved_by,reviewed_at,revoked_at,row_version'));
        $this->table(__('Exports', 'sabri-analytics-institutional-intelligence'), $this->rows('exports', 'export_uuid,project_uuid,requester_user_id,state,row_limit,file_sha256,expires_at,revoked_at,created_at,completed_at'));
        $this->table(__('Deletion jobs', 'sabri-analytics-institutional-intelligence'), $this->rows('deletion_jobs', 'job_uuid,source_module,source_version,state,retry_count,requested_at,completed_at,updated_at'));
        $this->close();
    }

    public function reports(): void
    {
        $this->requireCapability('smai_manage_reports');
        $this->open(__('Dashboards, Reports and Narratives', 'sabri-analytics-institutional-intelligence'), 'dashicons-media-document');
        $this->table(__('Dashboard definitions', 'sabri-analytics-institutional-intelligence'), $this->rows('dashboard_definitions', 'dashboard_id,dashboard_version,name,state,owner_user_id,project_uuid,expires_at,row_version,updated_at'));
        $this->table(__('Scheduled reports', 'sabri-analytics-institutional-intelligence'), $this->rows('reports', 'report_uuid,name,state,owner_user_id,project_uuid,schedule_rrule,next_run_at,expires_at,row_version,updated_at'));
        $this->table(__('Narrative insights', 'sabri-analytics-institutional-intelligence'), $this->rows('narratives', 'insight_uuid,state,title,ai_assisted,author_user_id,reviewer_user_id,row_version,updated_at'));
        $this->close();
    }

    public function experiments(): void
    {
        $this->requireCapability('smai_manage_experiments');
        $this->open(__('Experiments and Decision Evidence', 'sabri-analytics-institutional-intelligence'), 'dashicons-lightbulb');
        $this->table(__('Experiments', 'sabri-analytics-institutional-intelligence'), $this->rows('experiments', 'experiment_uuid,name,state,owner_user_id,assignment_owner,privacy_class,enhanced_review,starts_at,ends_at,row_version,updated_at'));
        $this->table(__('Analyses', 'sabri-analytics-institutional-intelligence'), $this->rows('experiment_analyses', 'analysis_uuid,experiment_uuid,analysis_version,state,result_hash,analyst_user_id,reviewer_user_id,created_at,updated_at'));
        $this->table(__('Decision records', 'sabri-analytics-institutional-intelligence'), $this->rows('decision_records', 'decision_uuid,subject_type,subject_ref,action_owner,approver_user_id,review_at,created_at,updated_at'));
        $this->close();
    }

    public function system(): void
    {
        $this->requireCapability('smai_audit');
        $health = $this->health->report(true);
        $audit = (new AuditVerifier($this->db))->verify(100000);
        $repair = (new RepairService($this->db))->check();
        $this->open(__('System, Audit and Repair', 'sabri-analytics-institutional-intelligence'), 'dashicons-admin-tools');
        $this->statusNotice($health);
        echo '<div class="smai-grid">';
        $this->card(__('Audit chain', 'sabri-analytics-institutional-intelligence'), (string) ($audit['status'] ?? 'unknown'), __('Hash-linked minimized evidence verification.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Schema', 'sabri-analytics-institutional-intelligence'), (string) ($health['schema_version'] ?? 'unknown'), __('Database migrations are additive and idempotent.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Repair status', 'sabri-analytics-institutional-intelligence'), (string) ($repair['status'] ?? 'unknown'), __('Only bounded, reversible safe repair is offered.', 'sabri-analytics-institutional-intelligence'));
        echo '</div>';
        echo '<section class="smai-panel"><h2>' . esc_html__('Safe repair', 'sabri-analytics-institutional-intelligence') . '</h2>';
        echo '<p>' . esc_html__('Safe repair recreates missing schema definitions and releases expired job leases. It does not activate runtime, purge data, alter metrics, or write to companion modules.', 'sabri-analytics-institutional-intelligence') . '</p>';
        if (current_user_can('smai_restore')) {
            echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
            wp_nonce_field('smai_safe_repair');
            echo '<input type="hidden" name="action" value="smai_safe_repair">';
            submit_button(__('Run safe repair', 'sabri-analytics-institutional-intelligence'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
        $this->table(__('Providers', 'sabri-analytics-institutional-intelligence'), $this->rows('providers', 'provider_id,provider_version,state,region_code,row_version,created_at,updated_at'));
        $this->table(__('Recent governance transitions', 'sabri-analytics-institutional-intelligence'), $this->rows('governance_transitions', 'object_type,object_id,from_state,to_state,actor_user_id,row_version_from,row_version_to,created_at'));
        $this->close();
    }

    public function safeRepair(): void
    {
        $this->requireCapability('smai_restore');
        check_admin_referer('smai_safe_repair');
        $result = (new RepairService($this->db))->safeRepair();
        $targetPage = current_user_can('smai_audit') ? 'smai-system' : 'smai-quality';
        $status = is_array($result) && ($result['status'] ?? '') === 'repaired' ? '1' : '0';
        wp_safe_redirect(add_query_arg(['page' => $targetPage, 'smai_repaired' => $status], admin_url('admin.php')));
        exit;
    }

    private function open(string $title, string $icon): void
    {
        echo '<div class="wrap smai-wrap" dir="auto"><h1><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span> ' . esc_html($title) . '</h1>';
        $repairStatus = isset($_GET['smai_repaired']) ? sanitize_key(wp_unslash((string) $_GET['smai_repaired'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($repairStatus === '1') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Safe repair completed. Review the fresh health evidence below.', 'sabri-analytics-institutional-intelligence') . '</p></div>';
        } elseif ($repairStatus === '0') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Safe repair completed with unresolved conditions. Review the health evidence and audit trail.', 'sabri-analytics-institutional-intelligence') . '</p></div>';
        }
    }

    private function close(): void
    {
        echo '<p class="smai-truth"><strong>' . esc_html__('Truth status:', 'sabri-analytics-institutional-intelligence') . '</strong> ' . esc_html__('Source candidate only. Staging acceptance, live deployment and operational acceptance are not implied.', 'sabri-analytics-institutional-intelligence') . '</p></div>';
    }

    private function requireCapability(string $capability): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not authorized to access this CF-05 surface.', 'sabri-analytics-institutional-intelligence'), '', ['response' => 403]);
        }
    }

    /** @param array<string,mixed> $health */
    private function statusNotice(array $health): void
    {
        $healthy = ($health['status'] ?? '') === 'healthy_within_declared_scope';
        $class = $healthy ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr($class) . ' inline"><p><strong>' . esc_html__('Declared status:', 'sabri-analytics-institutional-intelligence') . '</strong> ' . esc_html((string) ($health['status'] ?? 'unknown')) . '. ';
        echo esc_html__('Runtime:', 'sabri-analytics-institutional-intelligence') . ' <code>' . esc_html(RuntimeGate::state()) . '</code>. ';
        echo esc_html__('No production-completion claim is made.', 'sabri-analytics-institutional-intelligence') . '</p></div>';
    }

    private function card(string $title, string $value, string $description): void
    {
        echo '<section class="smai-card"><h2>' . esc_html($title) . '</h2><div class="smai-value">' . esc_html($value) . '</div><p>' . esc_html($description) . '</p></section>';
    }

    /** A failed SQL or unavailable schema is not a truthful empty table. */
    private function rows(string $table, string $columns, string $where = '1=1'): array|\WP_Error
    {
        if (preg_match('/^[a-z0-9_,]+$/D', $columns) !== 1
            || !in_array($where, ['1=1', "state='open'"], true)) {
            return new \WP_Error('smai_admin_query_invalid', 'A restricted analytics admin query was not approved.', ['status' => 400]);
        }
        if (!$this->db->exists($table)) {
            return new \WP_Error('smai_admin_source_unavailable', 'Analytics table availability is unverified.', ['status' => 503]);
        }
        $physical = $this->db->table($table);
        $result = $this->db->wpdb()->get_results("SELECT {$columns} FROM `{$physical}` WHERE {$where} ORDER BY id DESC LIMIT 100", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (!is_array($result)) {
            return new \WP_Error('smai_admin_sql_unavailable', 'Analytics table records could not be verified.', ['status' => 503]);
        }
        return $result;
    }

    private function groupedQuarantine(): array|\WP_Error
    {
        if (!$this->db->exists('quarantine')) {
            return new \WP_Error('smai_admin_source_unavailable', 'Analytics quarantine table availability is unverified.', ['status' => 503]);
        }
        $table = $this->db->table('quarantine');
        $rows = $this->db->wpdb()->get_results("SELECT reason_code,status,COUNT(*) AS total,MAX(updated_at) AS latest FROM `{$table}` GROUP BY reason_code,status ORDER BY total DESC LIMIT 100", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (!is_array($rows)) {
            return new \WP_Error('smai_admin_sql_unavailable', 'Analytics quarantine summary could not be verified.', ['status' => 503]);
        }
        return $rows;
    }

    /** A database connection error is Unknown, never zero published metrics. */
    private function countWhere(string $table, string $where): ?int
    {
        if (!in_array($where, ["state='published'", "state='active'"], true) || !$this->db->exists($table)) {
            return null;
        }
        $physical = $this->db->table($table);
        $raw = $this->db->wpdb()->get_var("SELECT COUNT(*) FROM `{$physical}` WHERE {$where}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ((!is_int($raw) && !is_string($raw))
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $raw) !== 1) {
            return null;
        }
        $count = filter_var((string) $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $count === false ? null : $count;
    }

    private function formatCount(?int $value): string
    {
        return $value === null ? 'Unavailable' : (string) $value;
    }

    /**
     * Accessible, stable, output-escaped admin table presenter.
     * A WP_Error is a degraded view, not a verified zero-row state.
     *
     * @param array<int,array<string,mixed>>|\WP_Error $rows
     */
    private function table(string $title, array|\WP_Error $rows): void
    {
        $tableId = wp_unique_id('smai-admin-table-');
        echo '<section class="smai-panel" aria-labelledby="' . esc_attr($tableId) . '">';
        echo '<h2 id="' . esc_attr($tableId) . '">' . esc_html($title) . '</h2>';
        if (is_wp_error($rows)) {
            echo '<p class="smai-state smai-state--warning" role="alert">';
            echo esc_html__('Unavailable: table evidence could not be verified. This is not a zero count.', 'sabri-analytics-institutional-intelligence');
            echo '</p></section>';
            return;
        }
        if ($rows === []) {
            echo '<p class="smai-state" role="status">';
            echo esc_html__('No records were returned by the verified query.', 'sabri-analytics-institutional-intelligence');
            echo '</p></section>';
            return;
        }
        if (!is_array($rows[0])) {
            echo '<p class="smai-state smai-state--warning" role="alert">';
            echo esc_html__('Unavailable: analytics rows are malformed.', 'sabri-analytics-institutional-intelligence');
            echo '</p></section>';
            return;
        }
        $headings = array_keys($rows[0]);
        if ($headings === [] || count($headings) > 24) {
            echo '<p class="smai-state smai-state--warning" role="alert">';
            echo esc_html__('Unavailable: analytics columns are malformed or exceed the limit.', 'sabri-analytics-institutional-intelligence');
            echo '</p></section>';
            return;
        }
        echo '<div class="smai-table-scroll" role="region" tabindex="0" aria-label="' . esc_attr($title) . '">';
        echo '<table class="widefat striped smai-data-table"><thead><tr>';
        foreach ($headings as $heading) {
            echo '<th scope="col">' . esc_html((string) $heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, 100) as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<tr>';
            foreach ($headings as $heading) {
                $value = $row[$heading] ?? null;
                $text = $value === null ? '—'
                    : (is_scalar($value) ? (string) $value : '[unavailable]');
                echo '<td>' . esc_html($text) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">' . esc_html__('Showing at most 100 current query rows; additional records require an authorized scoped view.', 'sabri-analytics-institutional-intelligence') . '</p>';
        echo '</section>';
    }

}
