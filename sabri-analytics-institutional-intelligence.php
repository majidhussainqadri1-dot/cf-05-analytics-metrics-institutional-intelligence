<?php
/**
 * Plugin Name: Sabri Analytics, Metrics and Institutional Intelligence
 * Plugin URI:  https://sabrihomeopathy.com/
 * Description: Conditional, privacy-safe analytics governance, derivative warehouse, semantic metrics, institutional dashboards, reports, experiments, retention and reproducible decision-support infrastructure for the Sabri Social Homeopathy Platform.
 * Version:     1.0.0-rc.3
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * Text Domain: sabri-analytics-institutional-intelligence
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SMAI_VERSION', '1.0.0-rc.3');
define('SMAI_SCHEMA_VERSION', '1.1.0');
define('SMAI_CONTRACT_VERSION', '1.2.0');
define('SMAI_FILE', __FILE__);
define('SMAI_DIR', plugin_dir_path(__FILE__));
define('SMAI_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sabri\\AnalyticsIntelligence\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || str_contains($relative, '..')) {
        return;
    }
    $path = SMAI_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [Sabri\AnalyticsIntelligence\Infrastructure\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Sabri\AnalyticsIntelligence\Infrastructure\Activator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Sabri\AnalyticsIntelligence\Plugin::instance()->boot();
});
