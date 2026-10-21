<?php
declare(strict_types=1);
/**
 * CF05-FR-002/005/007/017 20 executable DATE parser parity checks.
 * Synthetic data only; no WordPress/production database access.
 */
require __DIR__.'/bootstrap.php';
use Sabri\AnalyticsIntelligence\Domain\EventIngestionService;
use Sabri\AnalyticsIntelligence\Domain\TransformationEngine;
use Sabri\AnalyticsIntelligence\Domain\FunnelAttributionCalculator;
use Sabri\AnalyticsIntelligence\Domain\SnapshotService;

$valid='2026-10-21T09:00:10Z';
$offset='2026-10-21T14:00:10+05:00';
$validSql='2026-10-21 09:00:10';
$invalidNewline=$valid."\n";
$badDate='2026-02-30T09:00:10Z';
$badZone='2026-10-21T09:00:10';
$cases=[
 ['valid RFC3339 UTC',$valid,true],
 ['valid timezone offset',$offset,true],
 ['trailing LF MUST fail',$invalidNewline,false],
 ['impossible calendar date MUST fail',$badDate,false],
 ['ISO without timezone MUST fail',$badZone,false],
];
$checks=0;$failed=[];
$check=static function(string $label,bool $condition)use(&$checks,&$failed):void{
 ++$checks;
 if(!$condition)$failed[]="$checks $label";
};

$service=new ReflectionClass(EventIngestionService::class);
$ingestion=$service->newInstanceWithoutConstructor();
$ingestTime=$service->getMethod('strictTimestamp');
foreach($cases as [$label,$time,$expected]){
 $actual=$ingestTime->invoke($ingestion,$time);
 $check("ingestion $label",($actual!==null)===$expected);
}

$transformer=new TransformationEngine();
foreach($cases as [$label,$time,$expected]){
 $ok=false;
 try{
  $value=$transformer->map(['properties'=>['time'=>$time]],['stamp'=>['from'=>'properties.time','type'=>'timestamp','required'=>true]]);
  $ok=is_string($value['stamp']??null);
 }catch(Throwable){}
 $check("transformation $label",$ok===$expected);
}

$subject=hash('sha256','synthetic-subject');
$spec=['subject_field'=>'subject','step_field'=>'stage','time_field'=>'time',
 'eligible_field'=>'eligible','channel_field'=>'channel','entry_step'=>'start',
 'conversion_step'=>'end','window_seconds'=>86400,'attribution_policy'=>'first_touch'];
$event=static fn(string $stage,string $time):array=>[
 'subject'=>$subject,'stage'=>$stage,'time'=>$time,'eligible'=>true,'channel'=>'direct'];
foreach($cases as [$label,$time,$expected]){
 [$ratio,$numerator,$denominator]=FunnelAttributionCalculator::calculate(
  [$event('start','2026-10-21T09:00:00Z'),$event('end',$time)],$spec);
 $check("funnel $label",$denominator===1.0 && $numerator===($expected?1.0:0.0));
}
$reflection=new ReflectionClass(SnapshotService::class);
$snapshot=$reflection->newInstanceWithoutConstructor();
$timeMethod=$reflection->getMethod('strictTimestamp');
foreach($cases as [$label,$time,$expected]){
 $actual=$timeMethod->invoke($snapshot,$time);
 $check("snapshot $label",($actual!==null)===$expected);
}
echo "CF-05 fresh timestamp 20-case parity: ".($checks-count($failed))."/$checks passed.\n";
foreach($failed as $f)fwrite(STDERR,"FAIL $f\n");
exit($failed===[]?0:1);
