<?php
declare(strict_types=1);

/**
 * CF-05 V12.3 canonical timestamp regression, 2026-11-15.
 * Covers CF05-FR-005/010/013/023 and exact input-to-UTC normalization.
 * No WordPress database, live data, secrets, or provider calls are used.
 */
require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\MetricQueryService;
use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Domain\ExportService;
use Sabri\AnalyticsIntelligence\Domain\PrivacyGateway;

$cases = [
    ['2026-10-21T19:44:55Z', '2026-10-21 19:44:55'],
    ['2026-10-21T19:44:55.123456Z', '2026-10-21 19:44:55'],
    ['2026-10-21T19:44:55+05:00', '2026-10-21 14:44:55'],
    ['2024-02-29T23:59:59Z', '2024-02-29 23:59:59'],
    ["2026-10-21T19:44:55Z\n", null],
    ["2026-10-21T19:44:55Z\r\n", null],
    ["2026-10-21T19:44:55Z\n\n", null],
    ["2026-10-21T19:44:55+05:00\n", null],
    ["2026-10-21T19:44:55.1Z\n", null],
    ['2026-02-29T19:44:55Z', null],
    ['2026-10-21T24:00:00Z', null],
    ['2026-10-21T19:44:55', null],
    ['tomorrow', null],
];

$targets = [
    [MetricQueryService::class, 'strictTimestamp'],
    [BackfillService::class, 'normalizeDate'],
    [ExportService::class, 'optionalDate'],
    [PrivacyGateway::class, 'strictTimestamp'],
];
$failed = 0;
$passed = 0;
foreach ($targets as [$class, $method]) {
    $reflection = new ReflectionClass($class);
    $service = $reflection->newInstanceWithoutConstructor();
    $normalizer = $reflection->getMethod($method);
    foreach ($cases as [$input, $expectedUtc]) {
        $actual = $normalizer->invoke($service, $input);
        $actualUtc = $actual === null ? null : (is_int($actual) ? gmdate('Y-m-d H:i:s', $actual) : $actual);
        $ok = $actualUtc === $expectedUtc;
        echo ($ok ? 'PASS' : 'FAIL') . " V12.3 " . $reflection->getShortName() . "::" . $method
            . " input=" . json_encode($input, JSON_UNESCAPED_SLASHES)
            . " expected=" . var_export($expectedUtc, true)
            . " actual=" . var_export($actualUtc, true) . PHP_EOL;
        $ok ? $passed++ : $failed++;
    }
}
echo "CF-05 V12.3 canonical time regression: {$passed}/" . ($passed + $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
