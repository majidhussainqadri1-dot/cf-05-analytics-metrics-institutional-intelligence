<?php
declare(strict_types=1);
/** Fresh CF05-FR-017 reproducible event-time fixtures. NO live data/DB. */
require __DIR__ . '/bootstrap.php';
use Sabri\AnalyticsIntelligence\Domain\FunnelAttributionCalculator;
$spec=[
 'subject_field'=>'subject','step_field'=>'step','time_field'=>'time',
 'eligible_field'=>'eligible','channel_field'=>'channel',
 'entry_step'=>'started','conversion_step'=>'finished',
 'window_seconds'=>172800,'attribution_policy'=>'first_touch'
];
$base='2026-09-16T09:00:00Z'; $id=hash('sha256','synthetic learner');
$one=static fn(string $stage,string $stamp):array=>[
 'subject'=>$id,'step'=>$stage,'time'=>$stamp,'eligible'=>true,'channel'=>'direct'
];
$cases=[
 ['UTC leap-date valid','2028-02-29T09:00:30Z','2028-02-29T09:00:00Z',true],
 ['explicit +05 offset valid','2026-09-16T14:00:30+05:00',$base,true],
 ['UTC fraction valid','2026-09-16T09:00:30.123456Z',$base,true],
 ['explicit -05 valid','2026-09-16T04:00:30-05:00',$base,true],
 ['missing timezone rejected','2026-09-16T09:00:30',$base,false],
 ['SQL style timestamp rejected','2026-09-16 09:00:30',$base,false],
 ['invalid February date rejected','2026-02-30T09:00:30Z','2026-02-28T09:00:00Z',false],
 ['nonleap Feb29 rejected','2026-02-29T09:00:30Z','2026-02-28T09:00:00Z',false],
 ['invalid minute rejected','2026-09-16T09:60:30Z',$base,false],
 ['invalid hour rejected','2026-09-16T25:00:30Z',$base,false],
 ['relative date rejected','tomorrow',$base,false],
 ['relative phrase rejected','next friday',$base,false],
 ['missing seconds rejected','2026-09-16T09:00Z',$base,false],
 ['missing T separator rejected','2026-09-16 09:00:30+00:00',$base,false],
 ['seven-digit fractional precision rejected','2026-09-16T09:00:30.1234567Z',$base,false],
 ['time zone outside ±23 rejected','2026-09-16T09:00:30+25:00',$base,false],
 ['trailing context rejected','2026-09-16T09:00:30Z random',$base,false],
 ['invalid month rejected','2026-13-16T09:00:30Z',$base,false],
 ['invalid zero day rejected','2026-09-00T09:00:30Z',$base,false],
 ['timestamp without event time rejected','',$base,false],
];
$failed=[]; $checks=0;
foreach($cases as [$label,$testDate,$entryDate,$shouldConvert]){
 $rows=[$one('started',$entryDate),$one('finished',$testDate)];
 [$ratio,$num,$den]=FunnelAttributionCalculator::calculate($rows,$spec);
 $expected=$shouldConvert?1.0:0.0;
 $checks++;
 if($den!==1.0 || $num!==$expected || $ratio!==$expected){
  $failed[]="$label expected $expected conversion; actual ratio=".var_export($ratio,true).", numerator=$num, denominator=$den";
 }
}
echo "CF05 FR017 strict-timestamp regression: ".($checks-count($failed))."/$checks passing (synthetic)\n";
foreach($failed as $f)fwrite(STDERR,"FAIL $f\n");
exit($failed===[]?0:1);
