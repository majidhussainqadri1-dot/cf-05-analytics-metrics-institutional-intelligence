<?php
declare(strict_types=1);

/**
 * V12.3 CF05-FR-007/-010: SQL-read failure must not turn into event
 * processing success or a fabricated "no previous active build" result.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public mixed $activeRow = null;
        public mixed $datasetRows = false;
        public int $inserts = 0;
        public int $rollbacks = 0;
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_var(string $sql): mixed { return 1; }
        public function get_results(string $sql, mixed $format = null): mixed { return $this->datasetRows; }
        public function get_row(string $sql, mixed $format = null): mixed {
            if (str_contains($sql, 'smai_events')) {
                return [
                    'event_id' => '11111111-1111-4111-8111-111111111111',
                    'event_name' => 'lesson.completed',
                    'event_version' => '1.0.0',
                    'properties_json' => '{}',
                    'source_module' => 'file-05',
                    'source_environment' => 'staging',
                    'source_sequence' => null,
                    'occurred_at' => '2026-11-15 00:00:00',
                ];
            }
            return $this->activeRow;
        }
        public function query(string $sql): int {
            if ($sql === 'ROLLBACK') { $this->rollbacks++; }
            return 1;
        }
        public function insert(string $table, array $values): int { $this->inserts++; return 1; }
    }
}

use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Domain\PipelineService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$passes = 0;
$failures = 0;
function assert_read(bool $yes, string $name): void {
    global $passes, $failures;
    echo ($yes ? 'PASS' : 'FAIL') . ' V12.3 pipeline/backfill read ' . $name . PHP_EOL;
    $yes ? $passes++ : $failures++;
}
function raises(callable $action): bool {
    try { $action(); } catch (RuntimeException $e) { return true; }
    return false;
}

$db = new wpdb();
$pipe = new PipelineService(new Database($db));
$backfill = new BackfillService(new Database($db));
$pipeActive = (new ReflectionClass(PipelineService::class))->getMethod('activeBuild');
$backfillActive = (new ReflectionClass(BackfillService::class))->getMethod('activeBuildForComparison');

$db->datasetRows = false;
assert_read(raises(static fn() => $pipe->runJob(['event_id' => '11111111-1111-4111-8111-111111111111'])), 'dataset-list SQL failure leaves event unprocessed');
assert_read($db->inserts === 0, 'unavailable dataset list creates no projected rows');

$db->last_error = 'simulated SQL outage';
$db->activeRow = null;
assert_read(raises(static fn() => $pipeActive->invoke($pipe, 'education.events', '1.0.0', 5)), 'pipeline SQL lookup error never creates another active build');
assert_read($db->inserts === 0 && $db->rollbacks > 0, 'pipeline failed lookup rolls back without insertion');
assert_read(raises(static fn() => $backfillActive->invoke($backfill, 'education.events', '1.0.0')), 'backfill SQL lookup error never becomes no-active proof');

$db->last_error = '';
$db->activeRow = false;
assert_read(raises(static fn() => $pipeActive->invoke($pipe, 'education.events', '1.0.0', 5)), 'invalid pipeline lookup result denied');
assert_read(raises(static fn() => $backfillActive->invoke($backfill, 'education.events', '1.0.0')), 'invalid backfill lookup result denied');

$db->activeRow = null;
assert_read($backfillActive->invoke($backfill, 'education.events', '1.0.0') === null, 'successful absence of active build stays legitimate');
$new = $pipeActive->invoke($pipe, 'education.events', '1.0.0', 5);
assert_read(is_array($new) && is_string($new['build_uuid'] ?? null), 'successful absent build creates a new UUID');
assert_read($db->inserts === 1, 'only explicitly absent build permits insertion');

$db->activeRow = [
    'build_uuid' => '11111111-1111-4111-8111-111111111111',
    'row_count' => '12',
    'build_hash' => null, 'source_start' => null, 'source_end' => null,
];
$existing = $backfillActive->invoke($backfill, 'education.events', '1.0.0');
assert_read(is_array($existing) && $existing['row_count'] === 12, 'streaming active build can have null historical hash');
$reused = $pipeActive->invoke($pipe, 'education.events', '1.0.0', 5);
assert_read(is_array($reused) && $reused['build_uuid'] === $db->activeRow['build_uuid'], 'valid pipeline active build reused');

$db->activeRow['row_count'] = null;
assert_read(raises(static fn() => $backfillActive->invoke($backfill, 'education.events', '1.0.0')), 'missing active count denies comparison');
$db->activeRow['row_count'] = '12junk';
assert_read(raises(static fn() => $backfillActive->invoke($backfill, 'education.events', '1.0.0')), 'invalid active count denies comparison');
$db->activeRow['row_count'] = '12';
$db->activeRow['build_uuid'] = 'invalid-build-uuid';
assert_read(raises(static fn() => $pipeActive->invoke($pipe, 'education.events', '1.0.0', 5)), 'pipeline corrupt active identity denied');
assert_read(raises(static fn() => $backfillActive->invoke($backfill, 'education.events', '1.0.0')), 'backfill corrupt active identity denied');

echo 'CF-05 V12.3 pipeline/backfill read safety: ' . $passes . '/' . ($passes + $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
