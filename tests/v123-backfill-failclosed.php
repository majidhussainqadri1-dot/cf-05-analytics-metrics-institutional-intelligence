<?php
declare(strict_types=1);

/**
 * CF-05 V12.3 fail-closed backfill integrity regression.
 * A failed SQL read must not produce an empty event batch, zero count,
 * successful dry-run estimate, or SHA-256 hash of incomplete rows.
 */
require __DIR__ . '/bootstrap.php';

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public mixed $countResult = '0';
        public mixed $batchResult = [];
        public function prepare(string $query, mixed ...$args): string { return $query; }
        public function get_var(string $query): mixed { return $this->countResult; }
        public function get_results(string $query, mixed $format = null): mixed { return $this->batchResult; }
    }
}

use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$db = new wpdb();
$service = new BackfillService(new Database($db));
$reflection = new ReflectionClass(BackfillService::class);
$methods = [];
foreach (['checkedCount','sourceCount','sourceEventsBatch','buildHash'] as $name) {
    $methods[$name] = $reflection->getMethod($name);
}
$failures = 0;
$checks = 0;
function check_result(bool $condition, string $label): void {
    global $failures, $checks;
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' V12.3 backfill ' . $label . PHP_EOL;
    if (!$condition) { $failures++; }
}
function throws_runtime(callable $operation): bool {
    try { $operation(); } catch (RuntimeException $e) { return true; }
    return false;
}
$sources = [['event_name' => 'lesson.completed', 'event_version' => '1.0.0']];
$count = static fn() => $methods['sourceCount']->invoke($service, $sources, '2026-10-01 00:00:00', '2026-10-02 00:00:00');
$batch = static fn() => $methods['sourceEventsBatch']->invoke($service, $sources, '2026-10-01 00:00:00', '2026-10-02 00:00:00', 0, 1000);
$hash = static fn() => $methods['buildHash']->invoke($service, '11111111-1111-4111-8111-111111111111');

foreach ([false, null, 'bad', '-1', '1.5', '01', '9999999999999999999999999999999'] as $invalid) {
    $db->countResult = $invalid;
    check_result(throws_runtime($count), 'invalid SQL count ' . var_export($invalid, true) . ' rejected');
}
foreach (['0' => 0, '12' => 12, '100000000' => 100000000] as $raw => $expected) {
    $db->countResult = $raw;
    check_result($count() === $expected, 'canonical source count ' . $raw . ' accepted');
}
$db->countResult = false;
check_result($methods['sourceCount']->invoke($service, [], '2026-10-01 00:00:00', '2026-10-02 00:00:00') === 0, 'explicitly no sources is an empty count, not an SQL failure');

foreach ([false, null, 'not rows'] as $invalid) {
    $db->batchResult = $invalid;
    check_result(throws_runtime($batch), 'unavailable SQL event batch denied');
    check_result(throws_runtime($hash), 'unavailable SQL hash batch denied');
}
$db->batchResult = [];
check_result($batch() === [], 'successful zero-row event read accepted');
check_result($hash() === hash('sha256', ''), 'successful empty-build hash is deterministic');
$db->batchResult = [['id' => '1', 'row_hash' => str_repeat('a', 64)], ['id' => '3', 'row_hash' => str_repeat('b', 64)]];
$expectedHash = hash('sha256', str_repeat('a', 64) . "\n" . str_repeat('b', 64) . "\n");
check_result($hash() === $expectedHash, 'complete ordered build hash matches reproducible witness');
$db->batchResult = [['id' => '2', 'row_hash' => str_repeat('a', 64)], ['id' => '1', 'row_hash' => str_repeat('b', 64)]];
check_result(throws_runtime($hash), 'non-monotonic row evidence cannot be hashed');
$db->batchResult = [['id' => '1', 'row_hash' => 'corrupt']];
check_result(throws_runtime($hash), 'corrupt row hash cannot be hashed');
$db->batchResult = [['id' => false, 'row_hash' => str_repeat('a', 64)]];
check_result(throws_runtime($hash), 'missing row identity cannot be hashed');

echo "CF-05 V12.3 backfill integrity: " . ($checks - $failures) . "/{$checks}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
