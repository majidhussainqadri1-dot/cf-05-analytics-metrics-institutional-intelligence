<?php
declare(strict_types=1);

/**
 * CF05-FR-005 V12.3 source-ordering safety.
 * A failed MAX(source_sequence) query must never be interpreted as 0.
 */
require __DIR__ . '/bootstrap.php';
use Sabri\AnalyticsIntelligence\Domain\EventIngestionService;

$service = (new ReflectionClass(EventIngestionService::class))->newInstanceWithoutConstructor();
$normalizer = (new ReflectionClass(EventIngestionService::class))->getMethod('sourceSequenceMaximum');

$examples = [
    ['0',0], ['12',12], [0,0], [PHP_INT_MAX, PHP_INT_MAX],
    [false,null], [null,null], ['',null], ['-1',null],
    ['01',null], ['1.0',null], ['1e309',null],
    [1.5,null], [true,null], ['123junk',null],
    [PHP_INT_MAX . '0',null],
];
$passed=0;$failed=0;
foreach($examples as [$source,$expected]){
    $got=$normalizer->invoke($service,$source);
    $ok=$got===$expected;
    echo ($ok?'PASS':'FAIL'). ' CF05-FR-005 source sequence source=' . var_export($source,true) . ' expected=' . var_export($expected,true) . ' actual=' . var_export($got,true) . PHP_EOL;
    if($ok){$passed++;}else{$failed++;}
}
echo 'CF-05 V12.3 source sequence maximum: '.$passed.'/'.($passed+$failed).PHP_EOL;
exit($failed===0?0:1);
