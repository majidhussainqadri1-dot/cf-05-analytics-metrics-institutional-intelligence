<?php
declare(strict_types=1);

/**
 * CF-05 V12.3 privacy-accounting history integrity regressions.
 * This is synthetic WordPress-DB evidence; no personal records are used.
 */
require __DIR__ . '/bootstrap.php';

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('SMAI_PSEUDONYM_KEY')) { define('SMAI_PSEUDONYM_KEY', str_repeat('p', 48)); }

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public array $history = [];
        public int $inserts = 0;
        public function prepare(string $query, mixed ...$args): string { return $query; }
        public function get_var(string $query): int { return 1; }
        public function get_results(string $query, mixed $format = null): array { return $this->history; }
        public function query(string $query): int {
            if (str_contains($query, 'INSERT IGNORE')) { $this->inserts++; }
            return 1;
        }
    }
}

use Sabri\AnalyticsIntelligence\Domain\QueryPrivacyGuard;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$passes = 0;
$failures = 0;
function assert_policy(bool $pass, string $name): void {
    global $passes, $failures;
    echo ($pass ? 'PASS' : 'FAIL') . ' V12.3 privacy ledger ' . $name . PHP_EOL;
    $pass ? $passes++ : $failures++;
}
$valid = [
    'slice_fingerprint' => str_repeat('a', 64),
    'dimensions_fingerprint' => str_repeat('b', 64),
    'window_start' => '2026-11-13 00:00:00',
    'window_end' => '2026-11-13 01:00:00',
    'dimension_names' => [],
    'dimension_hashes' => [],
    'cohort_size' => 200,
    'privacy_cost' => 2,
];
$scenarios = [
    ['valid canonical history', [], true],
    ['terminal LF in old UTC time', ['window_start' => "2026-11-13 00:00:00\n"], false],
    ['terminal CRLF in old UTC time', ['window_start' => "2026-11-13 00:00:00\r\n"], false],
    ['relative old UTC time', ['window_start' => 'yesterday'], false],
    ['impossible calendar day', ['window_start' => '2026-02-29 00:00:00'], false],
    ['noninteger cohort string', ['cohort_size' => '200'], false],
    ['negative cohort', ['cohort_size' => -1], false],
    ['float cohort', ['cohort_size' => 200.0], false],
    ['overflow cohort', ['cohort_size' => '1e309'], false],
    ['zero privacy cost', ['privacy_cost' => 0], false],
    ['fractional privacy cost', ['privacy_cost' => 0.5], false],
    ['numeric-string privacy cost', ['privacy_cost' => '2'], false],
    ['negative privacy cost', ['privacy_cost' => -2], false],
];
foreach ($scenarios as [$name, $overrides, $shouldAllow]) {
    $wpdb = new wpdb();
    $history = array_replace($valid, $overrides);
    $wpdb->history = [[
        'request_hash' => str_repeat('c', 64),
        'response_json' => json_encode($history, JSON_THROW_ON_ERROR),
        'created_at' => '2026-11-15 00:00:00',
    ]];
    $guard = new QueryPrivacyGuard(new Database($wpdb));
    $result = $guard->evaluateAndRecord(
        'education.completion', '1.0.0',
        '11111111-1111-4111-8111-111111111111',
        5, 'approved institutional aggregate reporting',
        '2026-11-15 00:00:00', '2026-11-15 01:00:00',
        [], ['minimum_cohort' => 20], 200, 20
    );
    $allowed = $result === true && $wpdb->inserts === 1;
    $denied = $result instanceof WP_Error
        && $result->get_error_code() === 'smai_privacy_evidence_unavailable'
        && $wpdb->inserts === 0;
    assert_policy($shouldAllow ? $allowed : $denied, $name);
}
echo 'CF-05 V12.3 privacy-ledger integrity: ' . $passes . '/' . ($passes + $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
