<?php
declare(strict_types=1);

/** CF05-FR-013/-019 row evidence boundary: synthetic persisted storage shapes. */
require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\SnapshotEvidence;

$valid=[
    'value_decimal'=>'19.125',
    'numerator_decimal'=>'153',
    'denominator_decimal'=>'1200',
    'coverage_decimal'=>'0.98',
    'cohort_size'=>'1200',
    'quality_status'=>'green',
];
$cases=[
    ['complete valid persisted row',[],true],
    ['valid unknown numeric result',['value_decimal'=>null],true],
    ['valid nullable numerator',['numerator_decimal'=>null],true],
    ['valid suppressed zero-cohort',['cohort_size'=>'0','quality_status'=>'suppressed'],true],
    ['valid zero coverage',['coverage_decimal'=>'0'],true],
    ['valid full coverage',['coverage_decimal'=>'1'],true],
    ['invalid nonnumeric value',['value_decimal'=>'19garbage'],false],
    ['invalid exponent value',['value_decimal'=>'1e300'],false],
    ['invalid NaN value',['value_decimal'=>'NaN'],false],
    ['invalid Infinity value',['value_decimal'=>'Infinity'],false],
    ['invalid newline value',['value_decimal'=>"19\n"],false],
    ['invalid boolean value',['value_decimal'=>true],false],
    ['invalid array value',['value_decimal'=>[]],false],
    ['invalid missing cohort',['cohort_size'=>null],false],
    ['invalid fractional cohort',['cohort_size'=>'12.5'],false],
    ['invalid negative cohort',['cohort_size'=>'-1'],false],
    ['invalid prefixed cohort',['cohort_size'=>'012'],false],
    ['invalid cohort overflow',['cohort_size'=>'99999999999999999999999'],false],
    ['invalid negative coverage',['coverage_decimal'=>'-0.1'],false],
    ['invalid coverage above one',['coverage_decimal'=>'1.1'],false],
    ['invalid quality label',['quality_status'=>'current'],false],
    ['invalid blank quality',['quality_status'=>''],false],
    ['invalid malformed numerator',['numerator_decimal'=>'14xx'],false],
    ['invalid malformed denominator',['denominator_decimal'=>'22xx'],false],
    ['invalid malformed coverage',['coverage_decimal'=>'xx'],false],
];
$ok=0;$bad=0;
foreach ($cases as [$label,$change,$want]) {
    $snapshot=array_replace($valid,$change);
    $actual=SnapshotEvidence::validPublishedRow($snapshot);
    if ($actual===$want){$ok++;echo 'PASS V12.3 snapshot evidence '.$label.PHP_EOL;}
    else{$bad++;echo 'FAIL V12.3 snapshot evidence '.$label.PHP_EOL;}
}
$missing=$valid;
unset($missing['value_decimal']);
$correct=!SnapshotEvidence::validPublishedRow($missing);
if($correct){$ok++;echo 'PASS V12.3 snapshot evidence missing decimal field rejected'.PHP_EOL;}
else{$bad++;echo 'FAIL V12.3 snapshot evidence missing decimal field rejected'.PHP_EOL;}
echo 'CF05 V12.3 persisted snapshot row integrity: '.$ok.'/'.($ok+$bad).PHP_EOL;
exit($bad===0?0:1);
