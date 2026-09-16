<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\Future40Engine;
use Sabri\AnalyticsIntelligence\Domain\FutureFeatureRegistry;

function f40_truth(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

$ids = FutureFeatureRegistry::ids();
f40_truth(count($ids) === 40, 'Future-40 registry must contain exactly 40 features.');
f40_truth($ids[0] === 'CF05-FUT-001' && $ids[39] === 'CF05-FUT-040', 'Future-40 IDs must be complete and ordered.');

$inputs = [
'CF05-FUT-001'=>['state'=>'active','definition_hash'=>str_repeat('a',64),'owner_module'=>'cf-05','privacy_class'=>'C2','quality_status'=>'green','independent_approval'=>true],
'CF05-FUT-002'=>['nodes'=>['a','b'],'edges'=>[['from'=>'a','to'=>'b']]],
'CF05-FUT-003'=>['target'=>'a','edges'=>[['from'=>'a','to'=>'b'],['from'=>'b','to'=>'c']]],
'CF05-FUT-004'=>['left'=>['a'=>1],'right'=>['a'=>2],'left_value'=>1,'right_value'=>2],
'CF05-FUT-005'=>['from'=>'a','to'=>'c','edges'=>[['from'=>'a','to'=>'b'],['from'=>'b','to'=>'c']]],
'CF05-FUT-006'=>['entities'=>[['type'=>'metric'],['type'=>'dataset']]],
'CF05-FUT-007'=>['old_required_fields'=>['a'],'new_required_fields'=>['a','b']],
'CF05-FUT-008'=>['before'=>['a'=>'string'],'after'=>['a'=>'string','b'=>'integer']],
'CF05-FUT-009'=>['stream_ref'=>'events','from_checkpoint'=>'1','to_checkpoint'=>'2','max_events'=>100],
'CF05-FUT-010'=>['signals'=>[['status'=>'green'],['status'=>'warning']]],
'CF05-FUT-011'=>['series'=>[10,11,10,9,30]],
'CF05-FUT-012'=>['freshness_breach'=>true,'failed_jobs'=>1],
'CF05-FUT-013'=>['lag_seconds'=>60,'lag_slo_seconds'=>900,'failed_jobs'=>0,'quality_status'=>'green'],
'CF05-FUT-014'=>['age_seconds'=>7200,'target_seconds'=>3600],
'CF05-FUT-015'=>['privacy_risk'=>false,'availability_loss'=>true,'affected_percent'=>80],
'CF05-FUT-016'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'approved'=>true,'aggregate'=>true,'quality'=>'green']]],
'CF05-FUT-017'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'approved'=>true,'domain'=>'clinic']]],
'CF05-FUT-018'=>['series_a'=>[1,2,3,4],'series_b'=>[2,4,6,8]],
'CF05-FUT-019'=>['series'=>[1,2,3,4]],
'CF05-FUT-020'=>['series'=>[1,2,3,4],'horizon'=>2],
'CF05-FUT-021'=>['baseline'=>100,'changes'=>['traffic'=>10]],
'CF05-FUT-022'=>['projected_demand'=>80,'available_capacity'=>100],
'CF05-FUT-023'=>['costs'=>[10,20],'units'=>3],
'CF05-FUT-024'=>['cost_series'=>[10,10,11,10,50]],
'CF05-FUT-025'=>['allocated'=>10,'spent'=>3,'request'=>2],
'CF05-FUT-026'=>['epsilon'=>1,'sensitivity'=>1,'cohort_size'=>100,'minimum_cohort'=>20],
'CF05-FUT-027'=>['cohort_size'=>100,'dimensions'=>['country'],'sensitive_dimensions'=>0],
'CF05-FUT-028'=>['dimensions'=>['country'],'allowed_dimensions'=>['country','device'],'estimated_cohort_size'=>100,'minimum_cohort'=>20],
'CF05-FUT-029'=>['title'=>'Aggregate study','purpose'=>'quality','approved_aggregate_datasets'=>['d1']],
'CF05-FUT-030'=>['control_n'=>100,'variant_n'=>100,'control_rate'=>0.5,'variant_rate'=>0.6],
'CF05-FUT-031'=>['treatment_before'=>10,'treatment_after'=>15,'control_before'=>10,'control_after'=>11],
'CF05-FUT-032'=>['value'=>120,'benchmark'=>100,'benchmark_type'=>'historical'],
'CF05-FUT-033'=>['query'=>'metric clinic.visits from 2026-01-01 to 2026-01-31 by country','allowed_metric_ids'=>['clinic.visits'],'allowed_dimensions'=>['country']],
'CF05-FUT-034'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'approved'=>true,'quality'=>'green']],'citations'=>['snapshot:m1:v1']],
'CF05-FUT-035'=>['observation'=>'Metric increased.','inference'=>'Possible seasonal effect.','recommendation'=>'Review capacity.','citations'=>['snapshot:m1:v1'],'reviewer_user_id'=>2,'human_review_confirmed'=>true],
'CF05-FUT-036'=>['value'=>11,'threshold'=>10,'operator'=>'>'],
'CF05-FUT-037'=>['sections'=>['quality','traffic'],'rrule'=>'FREQ=WEEKLY'],
'CF05-FUT-038'=>['purpose'=>'institutional quality','metric_ids'=>['m1'],'data_classes'=>['C1'],'retention_summary'=>'aggregate snapshots only'],
'CF05-FUT-039'=>['count'=>5,'min'=>0,'max'=>10,'seed'=>2026],
'CF05-FUT-040'=>['restore_point_verified'=>true,'package_checksum_verified'=>true,'schema_version_match'=>true,'checkpoint_available'=>true,'deletion_floor_preserved'=>true],
];

foreach ($ids as $id) {
    $result = Future40Engine::evaluate($id, $inputs[$id] ?? []);
    f40_truth(($result['feature_id'] ?? '') === $id, $id . ' returned wrong feature id.');
    f40_truth(($result['advisory_only'] ?? false) === true, $id . ' must remain advisory only.');
    f40_truth(($result['aggregate_only'] ?? false) === true, $id . ' must remain aggregate only.');
    echo 'PASS: ', $id, ' ', FutureFeatureRegistry::get($id)['title'], PHP_EOL;
}

$thrown = false;
try { Future40Engine::evaluate('CF05-FUT-033', ['raw_query' => 'select * from private']); } catch (InvalidArgumentException) { $thrown = true; }
f40_truth($thrown, 'Future-40 engine must reject restricted input keys.');



// Round-4 semantic/adversarial regressions.
$r=Future40Engine::evaluate('CF05-FUT-001',['state'=>'active','definition_hash'=>'short','owner_module'=>'cf-05','privacy_class'=>'C2','quality_status'=>'green','independent_approval'=>true]);
f40_truth(($r['certification_status']??'')==='not_certified','Short definition hash must not certify.');
$r=Future40Engine::evaluate('CF05-FUT-003',['target'=>'','edges'=>[]]); f40_truth(($r['valid']??true)===false,'Impact analysis must reject missing target.');
$r=Future40Engine::evaluate('CF05-FUT-007',['old_required_fields'=>['a'],'new_required_fields'=>['a','b']]); f40_truth(($r['compatible']??true)===false,'Added required contract field must be breaking.');
$r=Future40Engine::evaluate('CF05-FUT-009',['stream_ref'=>'','from_checkpoint'=>'','to_checkpoint'=>'','max_events'=>0]); f40_truth(($r['valid']??true)===false,'Replay plan must reject missing identity/bound.');
$r=Future40Engine::evaluate('CF05-FUT-010',['signals'=>[]]); f40_truth(($r['overall']??'')==='unknown','Empty quality evidence must not be green.');
$r=Future40Engine::evaluate('CF05-FUT-011',['series'=>[10,10,10,10,20]]); f40_truth(($r['anomaly']??false)===true,'Changed value after zero-variance baseline must not be hidden.');
$r=Future40Engine::evaluate('CF05-FUT-013',['lag_seconds'=>0,'failed_jobs'=>0,'quality_status'=>'unknown']); f40_truth(($r['state']??'')==='unknown','Unknown pipeline quality must not be green.');
$r=Future40Engine::evaluate('CF05-FUT-017',['metrics'=>[['metric_id'=>'m','aggregate'=>true,'approved'=>false,'domain'=>'clinic','value'=>1]]]); f40_truth(($r['domain_count']??1)===0,'Unapproved metric must not enter domain pack.');
$r=Future40Engine::evaluate('CF05-FUT-018',['series_a'=>[1,1,1],'series_b'=>[1,2,3]]); f40_truth(array_key_exists('correlation',$r)&&$r['correlation']===null&&($r['reason']??'')==='zero_variance','Undefined correlation must remain unavailable.');
$r=Future40Engine::evaluate('CF05-FUT-022',['projected_demand'=>10,'available_capacity'=>0]); f40_truth(($r['valid']??true)===false&&($r['status']??'')==='unavailable','Zero capacity must fail closed.');
$r=Future40Engine::evaluate('CF05-FUT-025',['allocated'=>10,'spent'=>11,'request'=>0]); f40_truth(($r['over_budget']??false)===true&&($r['allowed']??true)===false,'Over-budget privacy ledger must fail closed.');
$r=Future40Engine::evaluate('CF05-FUT-027',['cohort_size'=>1,'dimensions'=>['country'],'sensitive_dimensions'=>1]); f40_truth(($r['approval_recommended']??true)===false&&($r['requires_review']??false)===true,'High re-identification risk must not recommend approval.');
$r=Future40Engine::evaluate('CF05-FUT-030',['control_n'=>10,'variant_n'=>10,'control_rate'=>1.2,'variant_rate'=>0.5]); f40_truth(($r['valid']??true)===false,'Experiment rates outside [0,1] must be rejected.');
$r=Future40Engine::evaluate('CF05-FUT-033',['query'=>'metric secret.metric by patient','allowed_metric_ids'=>['clinic.visits'],'allowed_dimensions'=>['country']]); f40_truth(($r['parsed']??true)===false&&($r['execution_authorized']??true)===false,'Natural-language metric query must enforce allowlists.');
$r=Future40Engine::evaluate('CF05-FUT-033',['query'=>'metric clinic.visits from 2026-02-30 to 2026-01-01','allowed_metric_ids'=>['clinic.visits'],'allowed_dimensions'=>[]]); f40_truth(($r['parsed']??true)===false,'Natural-language metric query must reject invalid/unordered dates.');
$r=Future40Engine::evaluate('CF05-FUT-034',['metrics'=>[['metric_id'=>'m','aggregate'=>true,'approved'=>true,'value'=>1]],'citations'=>[]]); f40_truth(($r['valid']??true)===false,'Copilot evidence must require citations.');
$r=Future40Engine::evaluate('CF05-FUT-035',['observation'=>'x','citations'=>['snapshot:x'],'reviewer_user_id'=>2]); f40_truth(($r['publishable']??true)===false,'Narrative must not become publishable without confirmed human review.');
$r=Future40Engine::evaluate('CF05-FUT-038',[]); f40_truth(($r['valid']??true)===false,'Transparency record must require complete governed metadata.');
$r=Future40Engine::evaluate('CF05-FUT-032',['value'=>INF,'benchmark'=>1]); f40_truth(($r['valid']??true)===false,'Non-finite numeric values must be rejected.');

echo "Round-4 semantic regressions passed.\n";

echo "All 40 Future-40 executable feature handlers passed.\n";
