<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence;

use Sabri\AnalyticsIntelligence\Admin\AdminPages;
use Sabri\AnalyticsIntelligence\CLI\Commands;
use Sabri\AnalyticsIntelligence\Domain\AccessProjectService;
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Http\RestController;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\IntegrationRegistry;
use Sabri\AnalyticsIntelligence\Infrastructure\JobRunner;
use Sabri\AnalyticsIntelligence\Infrastructure\RetentionRunner;
use Sabri\AnalyticsIntelligence\Infrastructure\SchemaMigrator;
use Sabri\AnalyticsIntelligence\Presentation\InsightsShortcode;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain(
            'sabri-analytics-institutional-intelligence',
            false,
            dirname(plugin_basename(SMAI_FILE)) . '/languages'
        );
        \Sabri\AnalyticsIntelligence\Infrastructure\Activator::registerSchedule();

        $this->upgradeIfNeeded();
        $database = new Database($GLOBALS['wpdb']);
        $health = new HealthService($database);

        (new RestController($database, $health))->register();
        (new AdminPages($database, $health))->register();
        (new RetentionRunner($database))->register();
        (new JobRunner($database))->register();
        (new InsightsShortcode($database))->register();
        (new IntegrationRegistry())->register();
        (new Commands($database))->register();

        add_action('smai_schedule_reports', static function () use ($database): void {
            (new ReportService($database))->scheduleDue();
        });
        add_action('smai_access_expiry', static function () use ($database): void {
            (new AccessProjectService($database))->expireDue();
        });

        add_filter('plugin_action_links_' . plugin_basename(SMAI_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=smai-insights')) . '">' . esc_html__('Insights', 'sabri-analytics-institutional-intelligence') . '</a>');
            return $links;
        });
    }

    private function upgradeIfNeeded(): void
    {
        if ((string) get_option('smai_schema_version', '') === SMAI_SCHEMA_VERSION) {
            return;
        }
        $lock = 'smai_schema_upgrade_lock';
        if (!add_option($lock, ['started_at' => time()], '', false)) {
            $current = get_option($lock);
            if (!is_array($current) || time() - (int) ($current['started_at'] ?? 0) < 600) {
                return;
            }
            delete_option($lock);
            if (!add_option($lock, ['started_at' => time()], '', false)) {
                return;
            }
        }
        try {
            SchemaMigrator::migrate();
        } finally {
            delete_option($lock);
        }
    }
}
