<?php
declare(strict_types=1);

/**
 * V12.3 CF05-FR-013: corrupt persisted values may not silently turn into
 * valid metric evidence. Synthetic regression: zero live/warehouse access.
 */
require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\MetricQueryService;

$service = (new ReflectionClass(MetricQueryService::class))->newInstanceWithoutConstructor();
$validate = (new ReflectionClass(MetricQueryService::class))->getMethod('validSnapshotDecimal');
$cases = [
    [null, true], ['0', true], ['0.000', true], ['123.4500', true],
    ['-7.5', true], [42, true], [2.25, true], ['-0.01', true],
    ['', false], ['NaN', false], ['Infinity', false],
    ['18junk', false], ["18\\n", false], ['1e900', false],
    ['1.2.3', false], [' 12', false], ['12 ', false], ['+12', false],
    [[], false], [true, false], [false, false], [INF, false],
];
$pass = 0;
foreach ($cases as $i => [$input, $expected]) {
    $actual = $validate->invoke($service, $input);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL metric snapshot case ' . $i . PHP_EOL);
        exit(1);
    }
    ++$pass;
}
echo 'CF-05 V12.3 persisted-decimal integrity: ' . $pass . '/' . count($cases) . PHP_EOL;
