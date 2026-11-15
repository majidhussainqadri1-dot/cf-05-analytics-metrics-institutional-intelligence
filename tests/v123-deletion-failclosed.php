<?php
declare(strict_types=1);

/**
 * CF05-FR-030/031 V12.3 regression: privacy deletion must never report
 * "all removed" when required SQL counts or derivative lists are unavailable.
 * Fixtures are synthetic and do not access WordPress or medical records.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public mixed $count = '0';
        public mixed $rows = [];
        public function prepare(string $sql, mixed ...$params): string { return $sql; }
        public function get_var(string $sql): mixed { return $this->count; }
        public function get_results(string $sql, mixed $format = null): mixed { return $this->rows; }
    }
}
use Sabri\AnalyticsIntelligence\Domain\DeletionService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$wpdb = new wpdb();
$service = new DeletionService(new Database($wpdb));
$methods = [];
$reflection = new ReflectionClass(DeletionService::class);
foreach (['activeProviders','eligibleCount','revokeExports','revokeReports','requiredCount'] as $method) {
    $methods[$method] = $reflection->getMethod($method);
}
$good = 0; $bad = 0;
function verify_deletion(bool $pass, string $name): void {
    global $good,$bad;
    echo ($pass ? 'PASS' : 'FAIL') . ' V12.3 deletion ' . $name . PHP_EOL;
    if ($pass) { $good++; } else { $bad++; }
}
function deletion_raises(callable $function): bool {
    try { $function(); } catch (\RuntimeException $error) { return true; }
    return false;
}
$count = static fn(mixed $raw) => $methods['requiredCount']->invoke($service,$raw,'synthetic deletion count');
foreach ([['0',0],['4',4],[15,15]] as [$raw,$expected]) {
    verify_deletion($count($raw) === $expected, 'canonical aggregate ' . $raw . ' accepted');
}
foreach ([false,null,'-1','01','1.5','not-a-count','1e309',PHP_INT_MAX . '0'] as $raw) {
    verify_deletion(deletion_raises(static fn() => $count($raw)), 'invalid aggregate denied');
}
$wpdb->count = false;
verify_deletion(deletion_raises(static fn() => $methods['eligibleCount']->invoke($service,'events',str_repeat('a',64))), 'failed eligible COUNT cannot certify deletion');
$wpdb->count = '2';
verify_deletion($methods['eligibleCount']->invoke($service,'events',str_repeat('a',64))===2, 'valid eligible COUNT is preserved');
$wpdb->rows = false;
verify_deletion(deletion_raises(static fn() => $methods['activeProviders']->invoke($service)), 'failed provider inventory cannot certify deletion');
verify_deletion(deletion_raises(static fn() => $methods['revokeExports']->invoke($service,['metric.demo@1.0.0'],'2026-11-15 00:00:00')), 'failed export list cannot become zero revocations');
verify_deletion(deletion_raises(static fn() => $methods['revokeReports']->invoke($service,['metric.demo@1.0.0'],'2026-11-15 00:00:00')), 'failed report list cannot become zero revocations');
$wpdb->rows = [];
verify_deletion($methods['activeProviders']->invoke($service)===[], 'successful empty provider inventory allowed');
verify_deletion($methods['revokeExports']->invoke($service,['metric.demo@1.0.0'],'2026-11-15 00:00:00')===0, 'successful empty export inventory allowed');
verify_deletion($methods['revokeReports']->invoke($service,['metric.demo@1.0.0'],'2026-11-15 00:00:00')===0, 'successful empty report inventory allowed');
echo 'CF-05 V12.3 deletion fail-closed: '.$good.'/'.($good+$bad).PHP_EOL;
exit($bad === 0 ? 0 : 1);
