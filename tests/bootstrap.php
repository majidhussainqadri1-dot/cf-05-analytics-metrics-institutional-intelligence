<?php

declare(strict_types=1);

if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sabri\\AnalyticsIntelligence\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) { require_once $path; }
});
