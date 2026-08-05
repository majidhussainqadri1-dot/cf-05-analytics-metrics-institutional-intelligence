<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Admin;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;

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
        add_submenu_page('smai-insights', __('Analytics Catalog', 'sabri-analytics-institutional-intelligence'), __('Catalog', 'sabri-analytics-institutional-intelligence'), 'smai_manage_catalog', 'smai-catalog', [$this, 'catalog']);
        add_submenu_page('smai-insights', __('Data Quality', 'sabri-analytics-institutional-intelligence'), __('Quality', 'sabri-analytics-institutional-intelligence'), 'smai_manage_quality', 'smai-quality', [$this, 'quality']);
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
        $health = $this->health->report(true);
        echo '<div class="wrap smai-wrap" dir="auto">';
        echo '<h1><span class="dashicons dashicons-chart-area" aria-hidden="true"></span> ' . esc_html__('Institutional Insights', 'sabri-analytics-institutional-intelligence') . '</h1>';
        $this->statusNotice($health);
        echo '<div class="smai-grid">';
        $this->card(__('Runtime State', 'sabri-analytics-institutional-intelligence'), (string) $health['runtime_state'], __('Conditional foundation; no production-completion claim.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Event Schemas', 'sabri-analytics-institutional-intelligence'), (string) ($health['tables']['event_schemas']['count'] ?? '—'), __('Versioned contracts only.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Metrics', 'sabri-analytics-institutional-intelligence'), (string) ($health['tables']['metrics']['count'] ?? '—'), __('Draft and governed metric definitions.', 'sabri-analytics-institutional-intelligence'));
        $this->card(__('Quarantine', 'sabri-analytics-institutional-intelligence'), (string) ($health['tables']['quarantine']['count'] ?? '—'), __('Rejected events never enter approved metrics.', 'sabri-analytics-institutional-intelligence'));
        echo '</div>';
        echo '<h2>' . esc_html__('Governance Boundaries', 'sabri-analytics-institutional-intelligence') . '</h2>';
        echo '<ul class="smai-boundaries"><li>' . esc_html__('CF-05 owns derivative analytics governance, not domain truth.', 'sabri-analytics-institutional-intelligence') . '</li><li>' . esc_html__('Raw clinical notes, private message bodies, identity evidence and payment secrets are prohibited.', 'sabri-analytics-institutional-intelligence') . '</li><li>' . esc_html__('Reports inform authorized humans and native owners; they do not execute clinical, financial, moderation or ranking decisions.', 'sabri-analytics-institutional-intelligence') . '</li></ul>';
        echo '</div>';
    }

    public function catalog(): void
    {
        $this->requireCapability('smai_manage_catalog');
        $wpdb = $this->db->wpdb();
        $events = $wpdb->get_results("SELECT event_name,event_version,owner_module,state,privacy_class,updated_at FROM `{$this->db->table('event_schemas')}` ORDER BY updated_at DESC LIMIT 100", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $metrics = $wpdb->get_results("SELECT metric_id,metric_version,name,owner_module,state,minimum_cohort,updated_at FROM `{$this->db->table('metrics')}` ORDER BY updated_at DESC LIMIT 100", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        echo '<div class="wrap smai-wrap"><h1><span class="dashicons dashicons-index-card" aria-hidden="true"></span> ' . esc_html__('Analytics Catalog', 'sabri-analytics-institutional-intelligence') . '</h1>';
        $this->table(__('Event Contracts', 'sabri-analytics-institutional-intelligence'), is_array($events) ? $events : []);
        $this->table(__('Metric Definitions', 'sabri-analytics-institutional-intelligence'), is_array($metrics) ? $metrics : []);
        echo '</div>';
    }

    public function quality(): void
    {
        $this->requireCapability('smai_manage_quality');
        $health = $this->health->report(true);
        $wpdb = $this->db->wpdb();
        $quarantine = $wpdb->get_results("SELECT reason_code,status,COUNT(*) AS total,MAX(updated_at) AS latest FROM `{$this->db->table('quarantine')}` GROUP BY reason_code,status ORDER BY total DESC LIMIT 100", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        echo '<div class="wrap smai-wrap"><h1><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> ' . esc_html__('Data Quality and Safety', 'sabri-analytics-institutional-intelligence') . '</h1>';
        $this->statusNotice($health);
        $this->table(__('Quarantine Summary', 'sabri-analytics-institutional-intelligence'), is_array($quarantine) ? $quarantine : []);
        echo '</div>';
    }

    private function requireCapability(string $capability): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not authorized to access this CF-05 surface.', 'sabri-analytics-institutional-intelligence'));
        }
    }

    /** @param array<string,mixed> $health */
    private function statusNotice(array $health): void
    {
        $class = $health['status'] === 'healthy_within_declared_scope' ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr($class) . ' inline"><p><strong>' . esc_html__('Declared status:', 'sabri-analytics-institutional-intelligence') . '</strong> ' . esc_html((string) $health['status']) . '. ' . esc_html__('Staging, live and operational acceptance remain separate gates.', 'sabri-analytics-institutional-intelligence') . '</p></div>';
    }

    private function card(string $title, string $value, string $description): void
    {
        echo '<section class="smai-card"><h2>' . esc_html($title) . '</h2><div class="smai-value">' . esc_html($value) . '</div><p>' . esc_html($description) . '</p></section>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function table(string $title, array $rows): void
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($rows === []) {
            echo '<div class="smai-empty"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html__('No governed records are available.', 'sabri-analytics-institutional-intelligence') . '</div>';
            return;
        }
        $headers = array_keys($rows[0]);
        echo '<div class="smai-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr($title) . '"><table class="widefat striped"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th scope="col">' . esc_html((string) $header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<td>' . esc_html(is_scalar($row[$header] ?? null) ? (string) $row[$header] : '—') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
