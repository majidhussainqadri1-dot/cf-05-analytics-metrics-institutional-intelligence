<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence;

use Sabri\AnalyticsIntelligence\Admin\AdminPages;
use Sabri\AnalyticsIntelligence\Domain\OperationsService;
use Sabri\AnalyticsIntelligence\Domain\RuntimeService;
use Sabri\AnalyticsIntelligence\Http\RestController;
use Sabri\AnalyticsIntelligence\Http\RuntimeController;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\RetentionRunner;
use Sabri\AnalyticsIntelligence\Infrastructure\SchemaUpgrade;

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

        load_plugin_textdomain('sabri-analytics-institutional-intelligence', false, dirname(plugin_basename(SMAI_FILE)) . '/languages');
        SchemaUpgrade::maybeUpgrade();

        $database = new Database($GLOBALS['wpdb']);
        $health = new HealthService($database);
        $runtime = new RuntimeService($database);
        $operations = new OperationsService($database);

        (new RestController($database, $health))->register();
        (new RuntimeController($runtime, $operations))->register();
        (new AdminPages($database, $health))->register();
        (new RetentionRunner($database))->register();

        add_action('smai_daily_retention', static function () use ($operations): void {
            $operations->runRetention();
        }, 20);

        add_filter('plugin_action_links_' . plugin_basename(SMAI_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=smai-insights')) . '">' . esc_html__('Insights', 'sabri-analytics-institutional-intelligence') . '</a>');
            return $links;
        });
    }
}
