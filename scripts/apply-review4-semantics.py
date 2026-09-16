#!/usr/bin/env python3
from pathlib import Path

engine_path=Path('src/Domain/Future40Engine.php')
s=engine_path.read_text(encoding='utf-8')

def rep(old,new,label):
    global s
    if old not in s:
        raise SystemExit(f'missing engine anchor: {label}')
    s=s.replace(old,new,1)

rep("'definition_hash' => self::nonEmpty($input['definition_hash'] ?? null),", "'definition_hash' => is_string($input['definition_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', (string) $input['definition_hash']) === 1,", 'metric hash')
rep("        $target = (string) ($input['target'] ?? '');\n        $edges = self::edges($input['edges'] ?? []);", "        $target = trim((string) ($input['target'] ?? ''));\n        if ($target === '') return ['valid' => false, 'reason' => 'missing_target', 'target' => '', 'downstream' => [], 'impact_count' => 0, 'dry_run' => true];\n        $edges = self::edges($input['edges'] ?? []);", 'impact target')
rep("return ['compatible' => $removed === [], 'breaking_removed_fields' => $removed, 'added_required_fields' => $added];", "return ['compatible' => $removed === [] && $added === [], 'breaking_removed_fields' => $removed, 'breaking_added_required_fields' => $added, 'added_required_fields' => $added];", 'contract compatibility')
rep("        return ['stream_ref' => (string) ($input['stream_ref'] ?? ''), 'from_checkpoint' => (string) ($input['from_checkpoint'] ?? ''), 'to_checkpoint' => (string) ($input['to_checkpoint'] ?? ''), 'max_events' => min(1000000, max(0, (int) ($input['max_events'] ?? 0))), 'mode' => 'dry_run', 'requires_reconciliation' => true];", "        $stream=trim((string)($input['stream_ref']??''));$from=trim((string)($input['from_checkpoint']??''));$to=trim((string)($input['to_checkpoint']??''));$max=min(1000000,max(0,(int)($input['max_events']??0)));\n        $valid=$stream!==''&&$from!==''&&$to!==''&&$max>0;\n        return ['valid'=>$valid,'reason'=>$valid?null:'missing_replay_identity_or_bound','stream_ref'=>$stream,'from_checkpoint'=>$from,'to_checkpoint'=>$to,'max_events'=>$max,'mode'=>'dry_run','requires_reconciliation'=>true];", 'historical replay')
rep("        $avg = $count ? $score / $count : 0.0;\n        return ['signal_count' => $count, 'risk_score' => round($avg, 3), 'overall' => $avg >= 2 ? 'degraded' : ($avg >= 1 ? 'warning' : 'green')];", "        if ($count === 0) return ['signal_count'=>0,'risk_score'=>null,'overall'=>'unknown'];\n        $avg = $score / $count;\n        return ['signal_count' => $count, 'risk_score' => round($avg, 3), 'overall' => $avg >= 2 ? 'degraded' : ($avg >= 1 ? 'warning' : 'green')];", 'quality empty')
rep("        $std = sqrt($variance / max(1, count($values) - 1)); $z = $std > 0 ? ($current - $mean) / $std : 0.0;\n        $threshold = max(2.0, min(6.0, (float) ($input['z_threshold'] ?? 3.0)));\n        return ['anomaly' => abs($z) >= $threshold, 'z_score' => round($z, 4), 'baseline_mean' => $mean, 'threshold' => $threshold];", "        $std = sqrt($variance / max(1, count($values) - 1));\n        $threshold = max(2.0, min(6.0, (float) ($input['z_threshold'] ?? 3.0)));\n        if ($std <= 0.0) { $changed=abs($current-$mean)>1e-12; return ['anomaly'=>$changed,'reason'=>'zero_variance_baseline','z_score'=>null,'baseline_mean'=>$mean,'threshold'=>$threshold]; }\n        $z = ($current - $mean) / $std;\n        return ['anomaly' => abs($z) >= $threshold, 'z_score' => round($z, 4), 'baseline_mean' => $mean, 'threshold' => $threshold];", 'zero variance anomaly')
rep("        $state = ($failed > 0 || $quality === 'degraded') ? 'degraded' : (($lag > (int) ($input['lag_slo_seconds'] ?? 900) || $quality === 'warning') ? 'warning' : 'green');", "        $allowedQuality=['green','warning','degraded','invalid','unknown'];\n        $state = ($failed > 0 || in_array($quality,['degraded','invalid'],true)) ? 'degraded' : (($lag > max(1,(int)($input['lag_slo_seconds']??900)) || $quality === 'warning') ? 'warning' : ($quality === 'green' ? 'green' : 'unknown'));\n        if(!in_array($quality,$allowedQuality,true))$state='degraded';", 'pipeline unknown')
rep("        foreach ($items as $item) { if (!is_array($item) || !($item['aggregate'] ?? false)) continue;", "        foreach ($items as $item) { if (!is_array($item) || !($item['aggregate'] ?? false) || !($item['approved'] ?? false)) continue;", 'domain approved')
rep("        $corr=($dx>0&&$dy>0)?$num/sqrt($dx*$dy):0.0;\n        return ['correlation'=>round($corr,4),'observations'=>$n,'causation_proven'=>false];", "        if($dx<=0||$dy<=0)return ['correlation'=>null,'reason'=>'zero_variance','observations'=>$n,'causation_proven'=>false];\n        $corr=$num/sqrt($dx*$dy);\n        return ['correlation'=>round($corr,4),'observations'=>$n,'causation_proven'=>false];", 'correlation variance')
rep("        $demand=max(0.0,(float)($input['projected_demand']??0));$capacity=max(0.000001,(float)($input['available_capacity']??0));$u=$demand/$capacity;\n        return ['utilization'=>round($u,4),'headroom'=>round(max(0.0,$capacity-$demand),4),'status'=>$u>1?'insufficient':($u>=0.8?'tight':'adequate')];", "        $demand=self::number($input['projected_demand']??null);$capacity=self::number($input['available_capacity']??null);\n        if($demand===null||$capacity===null||$demand<0||$capacity<=0)return ['valid'=>false,'utilization'=>null,'headroom'=>null,'status'=>'unavailable'];\n        $u=$demand/$capacity;\n        return ['valid'=>true,'utilization'=>round($u,4),'headroom'=>round(max(0.0,$capacity-$demand),4),'status'=>$u>1?'insufficient':($u>=0.8?'tight':'adequate')];", 'capacity')
rep("        $allocated=max(0.0,(float)($input['allocated']??0));$spent=max(0.0,(float)($input['spent']??0));$request=max(0.0,(float)($input['request']??0));$remaining=max(0.0,$allocated-$spent);\n        return ['allocated'=>$allocated,'spent'=>$spent,'remaining'=>$remaining,'request'=>$request,'allowed'=>$request<=$remaining,'remaining_after'=>$request<=$remaining?round($remaining-$request,8):$remaining];", "        $allocated=max(0.0,(float)($input['allocated']??0));$spent=max(0.0,(float)($input['spent']??0));$request=max(0.0,(float)($input['request']??0));$over=$spent>$allocated;$remaining=max(0.0,$allocated-$spent);$allowed=!$over&&$request<=$remaining;\n        return ['allocated'=>$allocated,'spent'=>$spent,'over_budget'=>$over,'remaining'=>$remaining,'request'=>$request,'allowed'=>$allowed,'remaining_after'=>$allowed?round($remaining-$request,8):$remaining];", 'privacy budget')
rep("        return ['risk_score'=>$score,'risk'=>$score>=70?'high':($score>=40?'medium':'low'),'approval_recommended'=>$score>=40];", "        return ['risk_score'=>$score,'risk'=>$score>=70?'high':($score>=40?'medium':'low'),'approval_recommended'=>$score<40,'requires_review'=>$score>=40];", 'reid direction')
rep("        if($control===null||$variant===null||$controlN<1||$variantN<1)return ['valid'=>false,'reason'=>'missing_aggregate_rates'];", "        if($control===null||$variant===null||$controlN<1||$variantN<1)return ['valid'=>false,'reason'=>'missing_aggregate_rates'];\n        if($control<0||$control>1||$variant<0||$variant>1)return ['valid'=>false,'reason'=>'rate_out_of_range'];", 'experiment rates')
old_nl="""        $q=trim((string)($input['query']??'')); if($q===''||strlen($q)>500)return ['parsed'=>false,'reason'=>'invalid_query'];
        $metric=null;$from=null;$to=null;$by=null;
        if(preg_match('/\\bmetric\\s+([a-z0-9._-]+)/i',$q,$m))$metric=$m[1];
        if(preg_match('/\\bfrom\\s+(\\d{4}-\\d{2}-\\d{2})\\b/i',$q,$m))$from=$m[1];
        if(preg_match('/\\bto\\s+(\\d{4}-\\d{2}-\\d{2})\\b/i',$q,$m))$to=$m[1];
        if(preg_match('/\\bby\\s+([a-z0-9._-]+)\\b/i',$q,$m))$by=$m[1];
        return ['parsed'=>$metric!==null,'metric_id'=>$metric,'from'=>$from,'to'=>$to,'dimension'=>$by,'execution_authorized'=>false];"""
new_nl="""        $q=trim((string)($input['query']??'')); if($q===''||strlen($q)>500)return ['parsed'=>false,'reason'=>'invalid_query','execution_authorized'=>false];
        $metric=null;$from=null;$to=null;$by=null;
        if(preg_match('/\\bmetric\\s+([a-z0-9._-]+)/i',$q,$m))$metric=$m[1];
        if(preg_match('/\\bfrom\\s+(\\d{4}-\\d{2}-\\d{2})\\b/i',$q,$m))$from=$m[1];
        if(preg_match('/\\bto\\s+(\\d{4}-\\d{2}-\\d{2})\\b/i',$q,$m))$to=$m[1];
        if(preg_match('/\\bby\\s+([a-z0-9._-]+)\\b/i',$q,$m))$by=$m[1];
        $allowedMetrics=self::stringList($input['allowed_metric_ids']??[]);$allowedDimensions=self::stringList($input['allowed_dimensions']??[]);
        if($metric===null||!in_array($metric,$allowedMetrics,true))return ['parsed'=>false,'reason'=>'metric_not_allowlisted','metric_id'=>$metric,'execution_authorized'=>false];
        if($by!==null&&!in_array($by,$allowedDimensions,true))return ['parsed'=>false,'reason'=>'dimension_not_allowlisted','metric_id'=>$metric,'dimension'=>$by,'execution_authorized'=>false];
        if(($from===null)!==($to===null))return ['parsed'=>false,'reason'=>'incomplete_date_range','metric_id'=>$metric,'execution_authorized'=>false];
        if($from!==null&&(!self::validDate($from)||!self::validDate($to)||$from>$to))return ['parsed'=>false,'reason'=>'invalid_date_range','metric_id'=>$metric,'execution_authorized'=>false];
        return ['parsed'=>true,'metric_id'=>$metric,'from'=>$from,'to'=>$to,'dimension'=>$by,'execution_authorized'=>false];"""
rep(old_nl,new_nl,'nl allowlist')
rep("        return ['evidence_summary'=>$lines,'citations'=>$citations,'inference_separated'=>true,'recommendation_requires_human'=>true];", "        return ['valid'=>$citations!==[]&&$lines!==[],'evidence_summary'=>$citations===[]?[]:$lines,'citations'=>$citations,'inference_separated'=>true,'recommendation_requires_human'=>true];", 'copilot citations')
rep("foreach(array_slice($metrics,0,20) as $m){if(!is_array($m)||!($m['aggregate']??false))continue;", "foreach(array_slice($metrics,0,20) as $m){if(!is_array($m)||!($m['aggregate']??false)||!($m['approved']??false))continue;", 'copilot approved')
rep("        $obs=trim((string)($input['observation']??''));$inf=trim((string)($input['inference']??''));$rec=trim((string)($input['recommendation']??''));$cit=self::stringList($input['citations']??[]);$reviewer=max(0,(int)($input['reviewer_user_id']??0));\n        return ['publishable'=>$obs!==''&&$cit!==[]&&$reviewer>0,'observation_present'=>$obs!=='','inference_present'=>$inf!=='','recommendation_present'=>$rec!=='','citation_count'=>count($cit),'human_review_required'=>true];", "        $obs=trim((string)($input['observation']??''));$inf=trim((string)($input['inference']??''));$rec=trim((string)($input['recommendation']??''));$cit=self::stringList($input['citations']??[]);$reviewer=max(0,(int)($input['reviewer_user_id']??0));$confirmed=($input['human_review_confirmed']??false)===true;\n        return ['publishable'=>$obs!==''&&$cit!==[]&&$reviewer>0&&$confirmed,'observation_present'=>$obs!=='','inference_present'=>$inf!=='','recommendation_present'=>$rec!=='','citation_count'=>count($cit),'human_review_confirmed'=>$confirmed,'human_review_required'=>true];", 'narrative confirmation')
rep("        return ['purpose'=>trim((string)($input['purpose']??'')),'metric_ids'=>self::stringList($input['metric_ids']??[]),'data_classes'=>self::stringList($input['data_classes']??[]),'retention_summary'=>trim((string)($input['retention_summary']??'')),'individual_tracking'=>false];", "        $purpose=trim((string)($input['purpose']??''));$metrics=self::stringList($input['metric_ids']??[]);$classes=self::stringList($input['data_classes']??[]);$retention=trim((string)($input['retention_summary']??''));\n        return ['valid'=>$purpose!==''&&$metrics!==[]&&$classes!==[]&&$retention!=='','purpose'=>$purpose,'metric_ids'=>$metrics,'data_classes'=>$classes,'retention_summary'=>$retention,'individual_tracking'=>false];", 'transparency valid')
rep("    private static function number(mixed $value): ?float { return is_int($value)||is_float($value)||(is_string($value)&&is_numeric($value)) ? (float)$value : null; }", "    private static function number(mixed $value): ?float { if(!(is_int($value)||is_float($value)||(is_string($value)&&is_numeric($value))))return null;$number=(float)$value;return is_finite($number)?$number:null; }\n    private static function validDate(?string $value): bool { if($value===null)return false;$date=\\DateTimeImmutable::createFromFormat('!Y-m-d',$value, new \\DateTimeZone('UTC'));return $date!==false&&$date->format('Y-m-d')===$value; }", 'finite number')
engine_path.write_text(s,encoding='utf-8')

# Strengthen semantic regression coverage and update positive inputs.
test_path=Path('tests/future40.php')
t=test_path.read_text(encoding='utf-8')
t=t.replace("'CF05-FUT-017'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'domain'=>'clinic']]],", "'CF05-FUT-017'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'approved'=>true,'domain'=>'clinic']]],")
t=t.replace("'CF05-FUT-033'=>['query'=>'metric clinic.visits from 2026-01-01 to 2026-01-31 by country'],", "'CF05-FUT-033'=>['query'=>'metric clinic.visits from 2026-01-01 to 2026-01-31 by country','allowed_metric_ids'=>['clinic.visits'],'allowed_dimensions'=>['country']],")
t=t.replace("'CF05-FUT-034'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'quality'=>'green']],'citations'=>['snapshot:m1:v1']],", "'CF05-FUT-034'=>['metrics'=>[['metric_id'=>'m1','value'=>1,'aggregate'=>true,'approved'=>true,'quality'=>'green']],'citations'=>['snapshot:m1:v1']],")
t=t.replace("'CF05-FUT-035'=>['observation'=>'Metric increased.','inference'=>'Possible seasonal effect.','recommendation'=>'Review capacity.','citations'=>['snapshot:m1:v1'],'reviewer_user_id'=>2],", "'CF05-FUT-035'=>['observation'=>'Metric increased.','inference'=>'Possible seasonal effect.','recommendation'=>'Review capacity.','citations'=>['snapshot:m1:v1'],'reviewer_user_id'=>2,'human_review_confirmed'=>true],")
anchor="echo \"All 40 Future-40 executable feature handlers passed.\\n\";"
extra=r'''

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
$r=Future40Engine::evaluate('CF05-FUT-018',['series_a'=>[1,1,1],'series_b'=>[1,2,3]]); f40_truth(($r['correlation']??1)===null&&($r['reason']??'')==='zero_variance','Undefined correlation must remain unavailable.');
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
'''
if anchor not in t: raise SystemExit('tests anchor missing')
t=t.replace(anchor,extra+'\n'+anchor,1)
test_path.write_text(t,encoding='utf-8')

# Add static markers so future source drift cannot silently reintroduce the defects.
check_path=Path('scripts/future40-check.py')
c=check_path.read_text(encoding='utf-8')
anchor="if manifest.get('version')!='1.0.0-rc.6': errors.append('manifest_version_not_rc6')"
markers="""# Review-4 semantic invariants.\nfor token in ['breaking_added_required_fields','zero_variance_baseline','over_budget','requires_review','metric_not_allowlisted','human_review_confirmed','is_finite','validDate']:\n    if token not in engine: errors.append(f'review4_semantic_guard_missing:{token}')\n"""
if anchor not in c: raise SystemExit('checker anchor missing')
c=c.replace(anchor,markers+anchor,1)
check_path.write_text(c,encoding='utf-8')
print('Review-4 semantic fixes and regressions applied.')
