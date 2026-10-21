<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Domain\FunnelAttributionCalculator;

function funnel_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$calc = [
    'type'=>'funnel','subject_field'=>'visitor','step_field'=>'stage',
    'time_field'=>'time','eligible_field'=>'eligible','channel_field'=>'channel',
    'entry_step'=>'entered','conversion_step'=>'completed','window_seconds'=>86400,
    'attribution_policy'=>'first_touch',
];
$definition = [
    'metric_id'=>'education.conversion','metric_version'=>'1.0.0',
    'name'=>'Course conversion','owner_module'=>'cf-05',
    'business_question'=>'What share of learners complete?',
    'numerator'=>'completed','denominator'=>'entered','grain'=>'session',
    'window'=>'monthly','timezone'=>'Asia/Karachi','dimensions'=>['channel'],
    'dimension_policies'=>['channel'=>['sensitivity'=>'standard','minimum_cohort'=>20,'max_cardinality'=>10]],
    'privacy_controls'=>['max_dimensions_per_query'=>4,'max_distinct_slices_per_hour'=>30,'max_privacy_budget_per_hour'=>80,'differencing_floor'=>20],
    'privacy_class'=>'C2','minimum_cohort'=>20,
    'source'=>['dataset_id'=>'education.events','dataset_version'=>'1.0.0'],
    'cohort_field'=>'visitor','calculation'=>$calc,
];
$validator = new MetricDefinitionValidator();
funnel_check($validator->errors($definition)===[], 'governed funnel metric definition accepted');
$bad=$definition;$bad['attribution']=['unbounded'=>'silent'];
funnel_check(in_array('unsupported_freeform_attribution',$validator->errors($bad),true), 'unimplemented freeform attribution rejected');
$bad=$definition;$bad['calculation']['window_seconds']=0;
funnel_check(in_array('invalid_funnel_window_seconds',$validator->errors($bad),true), 'invalid time window rejected');
$bad=$definition;$bad['cohort_field']='channel';
funnel_check(in_array('funnel_requires_distinct_subject_cohort',$validator->errors($bad),true), 'funnel cohort must be pseudonymous subject');
$make=static fn(string $id,string $stage,string $time,string $channel,bool $eligible=true):array=>[
    'visitor'=>hash('sha256',$id),'stage'=>$stage,'time'=>$time,'channel'=>$channel,'eligible'=>$eligible,
];
$rows=[];
for ($i=0;$i<22;$i++) {
    $rows[]=$make('person'.$i,'entered','2026-08-01T09:00:00Z','direct');
    if ($i<11) {
        $rows[]=$make('person'.$i,'completed','2026-08-01T10:00:00Z','search');
        $rows[]=$make('person'.$i,'completed','2026-08-01T11:00:00Z','search');
    }
}
$rows[]=$make('bot','entered','2026-08-01T09:00:00Z','direct',false);
$rows[]=$make('before','completed','2026-08-01T09:00:00Z','direct');
$rows[]=$make('before','entered','2026-08-02T09:00:00Z','direct');
$rows[]=$make('expired','entered','2026-08-01T09:00:00Z','direct');
$rows[]=$make('expired','completed','2026-08-10T09:00:00Z','direct');
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($rows,$calc);
funnel_check($num===11.0&&$den===24.0&&abs((float)$rate-11/24)<1e-10,'one subject, one conversion; bots, sequence and expiry excluded');
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($rows,$calc,'direct');
funnel_check($num===11.0&&$den===24.0,'first-touch assigns direct credit once');
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($rows,$calc,'search');
funnel_check($num===0.0&&$den===11.0,'first-touch does not overclaim conversion-channel credit');
$calc['attribution_policy']='last_touch';
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($rows,$calc,'search');
funnel_check($num===11.0&&$den===11.0,'last-touch credits completion channel');
$calc['attribution_policy']='linear';
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($rows,$calc,'search');
funnel_check($num===5.5&&$den===11.0,'linear multi-touch credits fractions, not duplicate conversions');
$simultaneous=[$make('sim','entered','2026-08-01T09:00:00Z','direct'),$make('sim','completed','2026-08-01T09:00:00Z','direct')];
[$rate,$num,$den]=FunnelAttributionCalculator::calculate($simultaneous,$calc);
funnel_check($num===0.0&&$den===1.0,'ambiguous same-timestamp conversion denied');
echo "CF-05 governed funnel attribution fixtures passed.\n";
