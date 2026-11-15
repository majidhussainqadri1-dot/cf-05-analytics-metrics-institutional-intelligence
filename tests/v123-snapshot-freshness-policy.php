<?php
declare(strict_types=1);

/**
 * CF05-FR-016 V12.3: false freshness is not "current green".
 * Tests precise historical/current/future/invalid MySQL UTC data-through
 * with a common policy for publishing, dashboard and metric endpoint.
 */
require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\SnapshotDisclosurePolicy;

$now=time();
$fresh=gmdate('Y-m-d H:i:s',$now-120);
$stale=gmdate('Y-m-d H:i:s',$now-2*DAY_IN_SECONDS);
$future=gmdate('Y-m-d H:i:s',$now+7200);
$skew=gmdate('Y-m-d H:i:s',$now+120);
$cases=[
    ['fresh green remains green','green',$fresh,'green'],
    ['stale green downgraded','green',$stale,'warning'],
    ['stale degraded never upgrades','degraded',$stale,'degraded'],
    ['stale warning remains warning','warning',$stale,'warning'],
    ['fresh unknown never upgrades','unknown',$fresh,'unknown'],
    ['null watermark is unknown','green',null,'unknown'],
    ['empty watermark is unknown','green','','unknown'],
    ['relative tomorrow never accepted','green','tomorrow','unknown'],
    ['invalid February date denied','green','2026-02-31 12:00:00','unknown'],
    ['newline at end denied','green',$fresh."\n",'unknown'],
    ['timestamp with offset not MySQL UTC','green',gmdate('c',$now-120),'unknown'],
    ['numeric watermark denied','green',$now-120,'unknown'],
    ['far future green invalidated to unknown','green',$future,'unknown'],
    ['far future degraded is unknown evidence','degraded',$future,'unknown'],
    ['small clock skew tolerated','green',$skew,'green'],
    ['suppressed remains suppressed','suppressed',$future,'suppressed'],
    ['invalidated remains invalidated','invalidated',$future,'invalidated'],
    ['bad persisted quality is unknown','unrecognized',$fresh,'unknown'],
];
$checks=[];
foreach($cases as [$label,$quality,$datum,$expected]){
    $got=SnapshotDisclosurePolicy::evaluate($quality,$datum,[],['freshness_seconds'=>3600]);
    $checks[$label]=$got['quality_status']===$expected;
}
$futureResult=SnapshotDisclosurePolicy::evaluate('green',$future,[],['freshness_seconds'=>3600]);
$checks['future watermark has no valid freshness seconds']=$futureResult['freshness_seconds']===null;
$checks['future watermark has explicit explanatory caveat']=in_array(
    'Data-through time is later than the permitted clock skew.',$futureResult['caveats'],true);
$invalid=SnapshotDisclosurePolicy::evaluate('green','2026-02-31 12:00:00',[],[]);
$checks['invalid calendar date yields null canonical data through']=$invalid['data_through']===null;
$valid=SnapshotDisclosurePolicy::evaluate('green',$fresh,[],[]);
$checks['valid UTC watermark returns RFC3339 normalized time']=$valid['data_through']===gmdate('c',$now-120);
$duplicate=SnapshotDisclosurePolicy::evaluate('green',null,['Data-through time is unavailable or noncanonical.'],[]);
$checks['repeat caveats deduplicate']=count($duplicate['caveats'])===1;
$source=file_get_contents(dirname(__DIR__).'/src/Domain/SnapshotService.php');
$api=file_get_contents(dirname(__DIR__).'/src/Domain/MetricQueryService.php');
$checks['snapshot writer enforces the common freshness policy']=str_contains((string)$source,'SnapshotDisclosurePolicy::evaluate(');
$checks['metric endpoint enforces the common freshness policy']=str_contains((string)$api,'SnapshotDisclosurePolicy::evaluate(');

$failed=0;
foreach($checks as $label=>$ok){
    echo ($ok?'PASS':'FAIL').' V12.3 freshness '.$label.PHP_EOL;
    if(!$ok)$failed++;
}
echo 'CF05 V12.3 freshness policy: '.(count($checks)-$failed).'/'.count($checks).PHP_EOL;
exit($failed===0?0:1);
