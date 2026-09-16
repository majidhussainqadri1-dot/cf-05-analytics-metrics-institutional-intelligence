<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class Future40Engine
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function evaluate(string $featureId, array $input): array
    {
        $featureId = strtoupper(trim($featureId));
        if (FutureFeatureRegistry::get($featureId) === null) {
            throw new \InvalidArgumentException('Unknown CF-05 future feature.');
        }
        self::assertAggregateSafe($input);
        $result = match ($featureId) {
            'CF05-FUT-001' => self::metricCertification($input),
            'CF05-FUT-002' => self::dependencyGraph($input),
            'CF05-FUT-003' => self::impactAnalysis($input),
            'CF05-FUT-004' => self::metricComparison($input),
            'CF05-FUT-005' => self::lineageExplorer($input),
            'CF05-FUT-006' => self::institutionalCatalog($input),
            'CF05-FUT-007' => self::contractCompatibility($input),
            'CF05-FUT-008' => self::schemaEvolution($input),
            'CF05-FUT-009' => self::historicalReplayPlan($input),
            'CF05-FUT-010' => self::qualityCommandCenter($input),
            'CF05-FUT-011' => self::anomalyDetection($input),
            'CF05-FUT-012' => self::rootCauseAssistant($input),
            'CF05-FUT-013' => self::pipelineHealth($input),
            'CF05-FUT-014' => self::freshnessSlo($input),
            'CF05-FUT-015' => self::incidentTriage($input),
            'CF05-FUT-016' => self::executiveScorecard($input),
            'CF05-FUT-017' => self::domainPacks($input),
            'CF05-FUT-018' => self::crossDomain($input),
            'CF05-FUT-019' => self::trendDetection($input),
            'CF05-FUT-020' => self::forecast($input),
            'CF05-FUT-021' => self::scenario($input),
            'CF05-FUT-022' => self::capacity($input),
            'CF05-FUT-023' => self::costIntelligence($input),
            'CF05-FUT-024' => self::costAnomaly($input),
            'CF05-FUT-025' => self::privacyBudget($input),
            'CF05-FUT-026' => self::differentialPrivacy($input),
            'CF05-FUT-027' => self::reidentificationRisk($input),
            'CF05-FUT-028' => self::cohortBuilder($input),
            'CF05-FUT-029' => self::researchWorkspace($input),
            'CF05-FUT-030' => self::experimentStudio($input),
            'CF05-FUT-031' => self::causalAnalysis($input),
            'CF05-FUT-032' => self::benchmarking($input),
            'CF05-FUT-033' => self::naturalLanguageMetricQuery($input),
            'CF05-FUT-034' => self::copilotEvidence($input),
            'CF05-FUT-035' => self::narrativeReview($input),
            'CF05-FUT-036' => self::proactiveAlert($input),
            'CF05-FUT-037' => self::scheduledBrief($input),
            'CF05-FUT-038' => self::transparencyRecord($input),
            'CF05-FUT-039' => self::syntheticData($input),
            'CF05-FUT-040' => self::disasterRecoverySimulation($input),
            default => throw new \LogicException('Future feature handler missing.'),
        };
        return ['feature_id' => $featureId, 'advisory_only' => true, 'aggregate_only' => true] + $result;
    }

    /** @param array<string,mixed> $input */
    private static function metricCertification(array $input): array
    {
        $state = strtolower((string) ($input['state'] ?? 'draft'));
        $checks = [
            'definition_hash' => self::nonEmpty($input['definition_hash'] ?? null),
            'owner' => self::nonEmpty($input['owner_module'] ?? null),
            'privacy' => self::nonEmpty($input['privacy_class'] ?? null),
            'quality' => in_array((string) ($input['quality_status'] ?? ''), ['green','warning'], true),
            'approval' => (bool) ($input['independent_approval'] ?? false),
        ];
        $certified = $state === 'active' && !in_array(false, $checks, true);
        return ['certification_status' => $certified ? 'certified' : 'not_certified', 'checks' => $checks];
    }

    /** @param array<string,mixed> $input */
    private static function dependencyGraph(array $input): array
    {
        $nodes = self::stringList($input['nodes'] ?? []);
        $edges = self::edges($input['edges'] ?? []);
        return ['nodes' => $nodes, 'edges' => $edges, 'node_count' => count($nodes), 'edge_count' => count($edges)];
    }

    /** @param array<string,mixed> $input */
    private static function impactAnalysis(array $input): array
    {
        $target = (string) ($input['target'] ?? '');
        $edges = self::edges($input['edges'] ?? []);
        $seen = [];
        $queue = [$target];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges as $edge) {
                if ($edge['from'] === $current && !isset($seen[$edge['to']])) {
                    $seen[$edge['to']] = true;
                    $queue[] = $edge['to'];
                }
            }
        }
        unset($seen[$target]);
        return ['target' => $target, 'downstream' => array_keys($seen), 'impact_count' => count($seen), 'dry_run' => true];
    }

    /** @param array<string,mixed> $input */
    private static function metricComparison(array $input): array
    {
        $left = is_array($input['left'] ?? null) ? $input['left'] : [];
        $right = is_array($input['right'] ?? null) ? $input['right'] : [];
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        $diff = [];
        foreach ($keys as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                $diff[$key] = ['left' => $left[$key] ?? null, 'right' => $right[$key] ?? null];
            }
        }
        $lv = self::number($input['left_value'] ?? null);
        $rv = self::number($input['right_value'] ?? null);
        return ['definition_diff' => $diff, 'value_delta' => ($lv !== null && $rv !== null) ? $rv - $lv : null];
    }

    /** @param array<string,mixed> $input */
    private static function lineageExplorer(array $input): array
    {
        $edges = self::edges($input['edges'] ?? []);
        $from = (string) ($input['from'] ?? '');
        $to = (string) ($input['to'] ?? '');
        $path = self::shortestPath($edges, $from, $to);
        return ['from' => $from, 'to' => $to, 'path' => $path, 'found' => $path !== []];
    }

    /** @param array<string,mixed> $input */
    private static function institutionalCatalog(array $input): array
    {
        $entities = is_array($input['entities'] ?? null) ? $input['entities'] : [];
        $counts = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) continue;
            $type = preg_replace('/[^a-z0-9_-]/i', '', (string) ($entity['type'] ?? 'unknown')) ?: 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return ['entity_counts' => $counts, 'total' => array_sum($counts)];
    }

    /** @param array<string,mixed> $input */
    private static function contractCompatibility(array $input): array
    {
        $old = self::stringList($input['old_required_fields'] ?? []);
        $new = self::stringList($input['new_required_fields'] ?? []);
        $removed = array_values(array_diff($old, $new));
        $added = array_values(array_diff($new, $old));
        return ['compatible' => $removed === [], 'breaking_removed_fields' => $removed, 'added_required_fields' => $added];
    }

    /** @param array<string,mixed> $input */
    private static function schemaEvolution(array $input): array
    {
        $before = is_array($input['before'] ?? null) ? $input['before'] : [];
        $after = is_array($input['after'] ?? null) ? $input['after'] : [];
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));
        $typeChanges = [];
        foreach (array_intersect(array_keys($before), array_keys($after)) as $field) {
            if ((string) $before[$field] !== (string) $after[$field]) $typeChanges[$field] = [(string) $before[$field], (string) $after[$field]];
        }
        return ['breaking' => $removed !== [] || $typeChanges !== [], 'removed_fields' => $removed, 'type_changes' => $typeChanges, 'simulation_only' => true];
    }

    /** @param array<string,mixed> $input */
    private static function historicalReplayPlan(array $input): array
    {
        return ['stream_ref' => (string) ($input['stream_ref'] ?? ''), 'from_checkpoint' => (string) ($input['from_checkpoint'] ?? ''), 'to_checkpoint' => (string) ($input['to_checkpoint'] ?? ''), 'max_events' => min(1000000, max(0, (int) ($input['max_events'] ?? 0))), 'mode' => 'dry_run', 'requires_reconciliation' => true];
    }

    /** @param array<string,mixed> $input */
    private static function qualityCommandCenter(array $input): array
    {
        $signals = is_array($input['signals'] ?? null) ? $input['signals'] : [];
        $weights = ['green'=>0,'warning'=>1,'degraded'=>2,'invalid'=>3,'unknown'=>1];
        $score = 0; $count = 0;
        foreach ($signals as $signal) { if (!is_array($signal)) continue; $status = strtolower((string) ($signal['status'] ?? 'unknown')); $score += $weights[$status] ?? 2; $count++; }
        $avg = $count ? $score / $count : 0.0;
        return ['signal_count' => $count, 'risk_score' => round($avg, 3), 'overall' => $avg >= 2 ? 'degraded' : ($avg >= 1 ? 'warning' : 'green')];
    }

    /** @param array<string,mixed> $input */
    private static function anomalyDetection(array $input): array
    {
        $values = self::numberList($input['series'] ?? []);
        if (count($values) < 4) return ['anomaly' => false, 'reason' => 'insufficient_series', 'z_score' => null];
        $current = array_pop($values); $mean = array_sum($values) / count($values); $variance = 0.0;
        foreach ($values as $v) $variance += ($v - $mean) ** 2;
        $std = sqrt($variance / max(1, count($values) - 1)); $z = $std > 0 ? ($current - $mean) / $std : 0.0;
        $threshold = max(2.0, min(6.0, (float) ($input['z_threshold'] ?? 3.0)));
        return ['anomaly' => abs($z) >= $threshold, 'z_score' => round($z, 4), 'baseline_mean' => $mean, 'threshold' => $threshold];
    }

    /** @param array<string,mixed> $input */
    private static function rootCauseAssistant(array $input): array
    {
        $candidates = [];
        if ((bool) ($input['freshness_breach'] ?? false)) $candidates[] = ['cause'=>'upstream_freshness','confidence'=>'high'];
        if ((int) ($input['failed_jobs'] ?? 0) > 0) $candidates[] = ['cause'=>'pipeline_job_failure','confidence'=>'high'];
        if ((bool) ($input['schema_change'] ?? false)) $candidates[] = ['cause'=>'schema_or_contract_change','confidence'=>'medium'];
        if ((bool) ($input['quality_rule_failure'] ?? false)) $candidates[] = ['cause'=>'data_quality_failure','confidence'=>'high'];
        if ((bool) ($input['provider_degradation'] ?? false)) $candidates[] = ['cause'=>'provider_degradation','confidence'=>'medium'];
        if ($candidates === []) $candidates[] = ['cause'=>'insufficient_evidence','confidence'=>'low'];
        return ['possible_causes' => $candidates, 'causation_proven' => false];
    }

    /** @param array<string,mixed> $input */
    private static function pipelineHealth(array $input): array
    {
        $lag = max(0, (int) ($input['lag_seconds'] ?? 0)); $failed = max(0, (int) ($input['failed_jobs'] ?? 0)); $quality = strtolower((string) ($input['quality_status'] ?? 'unknown'));
        $state = ($failed > 0 || $quality === 'degraded') ? 'degraded' : (($lag > (int) ($input['lag_slo_seconds'] ?? 900) || $quality === 'warning') ? 'warning' : 'green');
        return ['state' => $state, 'lag_seconds' => $lag, 'failed_jobs' => $failed, 'quality_status' => $quality];
    }

    /** @param array<string,mixed> $input */
    private static function freshnessSlo(array $input): array
    {
        $age = max(0, (int) ($input['age_seconds'] ?? 0)); $target = max(1, (int) ($input['target_seconds'] ?? 3600));
        return ['breached' => $age > $target, 'age_seconds' => $age, 'target_seconds' => $target, 'ratio' => round($age / $target, 4)];
    }

    /** @param array<string,mixed> $input */
    private static function incidentTriage(array $input): array
    {
        $privacy = (bool) ($input['privacy_risk'] ?? false); $availability = (bool) ($input['availability_loss'] ?? false); $incorrect = (bool) ($input['incorrect_published_metric'] ?? false); $scope = max(0, min(100, (int) ($input['affected_percent'] ?? 0)));
        $severity = $privacy ? 'SEV-0' : (($availability || $incorrect) && $scope >= 50 ? 'SEV-1' : (($availability || $incorrect) ? 'SEV-2' : ($scope > 0 ? 'SEV-3' : 'SEV-4')));
        return ['severity' => $severity, 'requires_evidence_preservation' => true, 'automatic_external_notice' => false];
    }

    /** @param array<string,mixed> $input */
    private static function executiveScorecard(array $input): array
    {
        $items = is_array($input['metrics'] ?? null) ? $input['metrics'] : []; $safe = [];
        foreach ($items as $item) { if (!is_array($item) || !($item['approved'] ?? false) || !($item['aggregate'] ?? false)) continue; $safe[] = ['metric_id'=>(string)($item['metric_id']??''),'value'=>self::number($item['value']??null),'quality'=>(string)($item['quality']??'unknown')]; }
        return ['scorecard' => $safe, 'included' => count($safe), 'excluded' => count($items)-count($safe)];
    }

    /** @param array<string,mixed> $input */
    private static function domainPacks(array $input): array
    {
        $items = is_array($input['metrics'] ?? null) ? $input['metrics'] : []; $packs = [];
        foreach ($items as $item) { if (!is_array($item) || !($item['aggregate'] ?? false)) continue; $domain = preg_replace('/[^a-z0-9_-]/i','',(string)($item['domain']??'other')) ?: 'other'; $packs[$domain][] = ['metric_id'=>(string)($item['metric_id']??''),'value'=>self::number($item['value']??null)]; }
        ksort($packs); return ['packs'=>$packs,'domain_count'=>count($packs)];
    }

    /** @param array<string,mixed> $input */
    private static function crossDomain(array $input): array
    {
        $x = self::numberList($input['series_a'] ?? []); $y = self::numberList($input['series_b'] ?? []); $n = min(count($x), count($y));
        if ($n < 3) return ['correlation'=>null,'reason'=>'insufficient_series','causation_proven'=>false];
        $x=array_slice($x,0,$n);$y=array_slice($y,0,$n);$mx=array_sum($x)/$n;$my=array_sum($y)/$n;$num=0.0;$dx=0.0;$dy=0.0;
        for($i=0;$i<$n;$i++){ $a=$x[$i]-$mx;$b=$y[$i]-$my;$num+=$a*$b;$dx+=$a*$a;$dy+=$b*$b; }
        $corr=($dx>0&&$dy>0)?$num/sqrt($dx*$dy):0.0;
        return ['correlation'=>round($corr,4),'observations'=>$n,'causation_proven'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function trendDetection(array $input): array
    {
        $series = self::numberList($input['series'] ?? []); $n=count($series); if($n<2) return ['trend'=>'insufficient','slope'=>null];
        [$slope,$intercept]=self::linearFit($series); $epsilon=max(0.000001,(float)($input['flat_epsilon']??0.001));
        return ['trend'=>abs($slope)<$epsilon?'stable':($slope>0?'rising':'falling'),'slope'=>round($slope,6),'intercept'=>round($intercept,6)];
    }

    /** @param array<string,mixed> $input */
    private static function forecast(array $input): array
    {
        $series=self::numberList($input['series']??[]);$h=min(24,max(1,(int)($input['horizon']??1)));$n=count($series); if($n<3) return ['forecast'=>[],'reason'=>'insufficient_series'];
        [$slope,$intercept]=self::linearFit($series);$out=[]; for($i=0;$i<$h;$i++)$out[]=round($intercept+$slope*($n+$i),6);
        return ['forecast'=>$out,'method'=>'bounded_linear_trend','uncertainty_note'=>'Forecast is scenario support, not fact.'];
    }

    /** @param array<string,mixed> $input */
    private static function scenario(array $input): array
    {
        $baseline=self::number($input['baseline']??0)??0.0;$changes=is_array($input['changes']??null)?$input['changes']:[];$value=$baseline;$applied=[];
        foreach($changes as $name=>$pct){$p=max(-100.0,min(1000.0,(float)$pct));$value*=(1+$p/100);$applied[(string)$name]=$p;}
        return ['baseline'=>$baseline,'changes_percent'=>$applied,'scenario_value'=>round($value,6),'decision_authority'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function capacity(array $input): array
    {
        $demand=max(0.0,(float)($input['projected_demand']??0));$capacity=max(0.000001,(float)($input['available_capacity']??0));$u=$demand/$capacity;
        return ['utilization'=>round($u,4),'headroom'=>round(max(0.0,$capacity-$demand),4),'status'=>$u>1?'insufficient':($u>=0.8?'tight':'adequate')];
    }

    /** @param array<string,mixed> $input */
    private static function costIntelligence(array $input): array
    {
        $costs=self::numberList($input['costs']??[]);$units=max(0.0,(float)($input['units']??0));$total=array_sum($costs);
        return ['total_cost'=>round($total,6),'unit_cost'=>$units>0?round($total/$units,6):null,'cost_points'=>count($costs)];
    }

    /** @param array<string,mixed> $input */
    private static function costAnomaly(array $input): array
    {
        $result=self::anomalyDetection(['series'=>$input['cost_series']??[],'z_threshold'=>$input['z_threshold']??3.0]);
        return ['cost_anomaly'=>$result['anomaly'],'z_score'=>$result['z_score'],'baseline_mean'=>$result['baseline_mean']??null];
    }

    /** @param array<string,mixed> $input */
    private static function privacyBudget(array $input): array
    {
        $allocated=max(0.0,(float)($input['allocated']??0));$spent=max(0.0,(float)($input['spent']??0));$request=max(0.0,(float)($input['request']??0));$remaining=max(0.0,$allocated-$spent);
        return ['allocated'=>$allocated,'spent'=>$spent,'remaining'=>$remaining,'request'=>$request,'allowed'=>$request<=$remaining,'remaining_after'=>$request<=$remaining?round($remaining-$request,8):$remaining];
    }

    /** @param array<string,mixed> $input */
    private static function differentialPrivacy(array $input): array
    {
        $epsilon=max(0.01,min(20.0,(float)($input['epsilon']??1.0)));$sensitivity=max(0.000001,(float)($input['sensitivity']??1.0));$cohort=max(0,(int)($input['cohort_size']??0));$minimum=max(20,(int)($input['minimum_cohort']??20));
        return ['epsilon'=>$epsilon,'sensitivity'=>$sensitivity,'laplace_scale'=>round($sensitivity/$epsilon,8),'release_allowed'=>$cohort>=$minimum,'simulation_only'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function reidentificationRisk(array $input): array
    {
        $cohort=max(0,(int)($input['cohort_size']??0));$dims=count(self::stringList($input['dimensions']??[]));$sensitive=max(0,(int)($input['sensitive_dimensions']??0));
        $score=min(100, ($cohort<20?60:($cohort<50?30:10)) + min(30,$dims*5) + min(30,$sensitive*10));
        return ['risk_score'=>$score,'risk'=>$score>=70?'high':($score>=40?'medium':'low'),'approval_recommended'=>$score>=40];
    }

    /** @param array<string,mixed> $input */
    private static function cohortBuilder(array $input): array
    {
        $requested=self::stringList($input['dimensions']??[]);$allowed=self::stringList($input['allowed_dimensions']??[]);$invalid=array_values(array_diff($requested,$allowed));$size=max(0,(int)($input['estimated_cohort_size']??0));$minimum=max(20,(int)($input['minimum_cohort']??20));
        return ['valid'=>$invalid===[]&&$size>=$minimum,'invalid_dimensions'=>$invalid,'estimated_cohort_size'=>$size,'minimum_cohort'=>$minimum];
    }

    /** @param array<string,mixed> $input */
    private static function researchWorkspace(array $input): array
    {
        $title=trim((string)($input['title']??''));$purpose=trim((string)($input['purpose']??''));$datasets=self::stringList($input['approved_aggregate_datasets']??[]);
        return ['valid'=>$title!==''&&$purpose!==''&&$datasets!==[],'title'=>$title,'purpose'=>$purpose,'datasets'=>$datasets,'raw_data_allowed'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function experimentStudio(array $input): array
    {
        $controlN=max(0,(int)($input['control_n']??0));$variantN=max(0,(int)($input['variant_n']??0));$control=self::number($input['control_rate']??null);$variant=self::number($input['variant_rate']??null);
        if($control===null||$variant===null||$controlN<1||$variantN<1)return ['valid'=>false,'reason'=>'missing_aggregate_rates'];
        $delta=$variant-$control;
        return ['valid'=>true,'absolute_effect'=>round($delta,8),'relative_effect'=>$control!=0?round($delta/$control,8):null,'sample_size'=>$controlN+$variantN,'human_decision_required'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function causalAnalysis(array $input): array
    {
        foreach(['treatment_before','treatment_after','control_before','control_after'] as $k) if(self::number($input[$k]??null)===null)return ['valid'=>false,'reason'=>'missing_did_input'];
        $did=((float)$input['treatment_after']-(float)$input['treatment_before'])-((float)$input['control_after']-(float)$input['control_before']);
        return ['valid'=>true,'difference_in_differences'=>round($did,8),'assumptions'=>['parallel_trends_required','aggregate_groups_only'],'causation_proven'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function benchmarking(array $input): array
    {
        $value=self::number($input['value']??null);$benchmark=self::number($input['benchmark']??null); if($value===null||$benchmark===null)return ['valid'=>false];
        $delta=$value-$benchmark; return ['valid'=>true,'delta'=>round($delta,8),'percent_delta'=>$benchmark!=0?round($delta/$benchmark*100,4):null,'benchmark_type'=>(string)($input['benchmark_type']??'approved')];
    }

    /** @param array<string,mixed> $input */
    private static function naturalLanguageMetricQuery(array $input): array
    {
        $q=trim((string)($input['query']??'')); if($q===''||strlen($q)>500)return ['parsed'=>false,'reason'=>'invalid_query'];
        $metric=null;$from=null;$to=null;$by=null;
        if(preg_match('/\bmetric\s+([a-z0-9._-]+)/i',$q,$m))$metric=$m[1];
        if(preg_match('/\bfrom\s+(\d{4}-\d{2}-\d{2})\b/i',$q,$m))$from=$m[1];
        if(preg_match('/\bto\s+(\d{4}-\d{2}-\d{2})\b/i',$q,$m))$to=$m[1];
        if(preg_match('/\bby\s+([a-z0-9._-]+)\b/i',$q,$m))$by=$m[1];
        return ['parsed'=>$metric!==null,'metric_id'=>$metric,'from'=>$from,'to'=>$to,'dimension'=>$by,'execution_authorized'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function copilotEvidence(array $input): array
    {
        $metrics=is_array($input['metrics']??null)?$input['metrics']:[];$citations=self::stringList($input['citations']??[]);$lines=[];
        foreach(array_slice($metrics,0,20) as $m){if(!is_array($m)||!($m['aggregate']??false))continue;$id=(string)($m['metric_id']??'metric');$value=self::number($m['value']??null);$quality=(string)($m['quality']??'unknown');$lines[]=$id.' = '.($value===null?'n/a':(string)$value).' (quality '.$quality.')';}
        return ['evidence_summary'=>$lines,'citations'=>$citations,'inference_separated'=>true,'recommendation_requires_human'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function narrativeReview(array $input): array
    {
        $obs=trim((string)($input['observation']??''));$inf=trim((string)($input['inference']??''));$rec=trim((string)($input['recommendation']??''));$cit=self::stringList($input['citations']??[]);$reviewer=max(0,(int)($input['reviewer_user_id']??0));
        return ['publishable'=>$obs!==''&&$cit!==[]&&$reviewer>0,'observation_present'=>$obs!=='','inference_present'=>$inf!=='','recommendation_present'=>$rec!=='','citation_count'=>count($cit),'human_review_required'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function proactiveAlert(array $input): array
    {
        $value=self::number($input['value']??null);$threshold=self::number($input['threshold']??null);$operator=(string)($input['operator']??'>');$trigger=false;
        if($value!==null&&$threshold!==null){$trigger=match($operator){'>'=>$value>$threshold,'>='=>$value>=$threshold,'<'=>$value<$threshold,'<='=>$value<=$threshold,'==' => abs($value-$threshold)<1e-12,default=>false};}
        return ['triggered'=>$trigger,'value'=>$value,'threshold'=>$threshold,'operator'=>$operator,'delivery_requires_role_scope'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function scheduledBrief(array $input): array
    {
        $sections=self::stringList($input['sections']??[]);$rrule=trim((string)($input['rrule']??''));
        return ['valid'=>$sections!==[]&&$rrule!==''&&strlen($rrule)<=500,'sections'=>$sections,'rrule'=>$rrule,'approved_aggregates_only'=>true];
    }

    /** @param array<string,mixed> $input */
    private static function transparencyRecord(array $input): array
    {
        return ['purpose'=>trim((string)($input['purpose']??'')),'metric_ids'=>self::stringList($input['metric_ids']??[]),'data_classes'=>self::stringList($input['data_classes']??[]),'retention_summary'=>trim((string)($input['retention_summary']??'')),'individual_tracking'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function syntheticData(array $input): array
    {
        $count=min(500,max(1,(int)($input['count']??10)));$min=(float)($input['min']??0);$max=(float)($input['max']??100);if($max<$min)[$min,$max]=[$max,$min];$seed=(int)($input['seed']??2026);$state=$seed&0x7fffffff;$rows=[];
        for($i=0;$i<$count;$i++){$state=(1103515245*$state+12345)&0x7fffffff;$u=$state/2147483647;$rows[]=['synthetic_index'=>$i+1,'value'=>round($min+($max-$min)*$u,6)];}
        return ['rows'=>$rows,'synthetic'=>true,'real_person_data_used'=>false];
    }

    /** @param array<string,mixed> $input */
    private static function disasterRecoverySimulation(array $input): array
    {
        $checks=['restore_point'=>(bool)($input['restore_point_verified']??false),'package_checksum'=>(bool)($input['package_checksum_verified']??false),'schema_match'=>(bool)($input['schema_version_match']??false),'checkpoint'=>(bool)($input['checkpoint_available']??false),'deletion_floor'=>(bool)($input['deletion_floor_preserved']??false)];
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'mode'=>'simulation_only','production_restore_performed'=>false];
    }

    private static function nonEmpty(mixed $value): bool { return is_string($value) ? trim($value) !== '' : $value !== null; }
    private static function number(mixed $value): ?float { return is_int($value)||is_float($value)||(is_string($value)&&is_numeric($value)) ? (float)$value : null; }
    /** @return array<int,float> */
    private static function numberList(mixed $value): array { if(!is_array($value))return[];$out=[];foreach($value as $v){$n=self::number($v);if($n!==null)$out[]=$n;}return$out; }
    /** @return array<int,string> */
    private static function stringList(mixed $value): array { if(!is_array($value))return[];$out=[];foreach($value as $v){if(is_scalar($v)){ $s=trim((string)$v);if($s!==''&&strlen($s)<=190)$out[]=$s;}}return array_values(array_unique($out)); }
    /** @return array<int,array{from:string,to:string}> */
    private static function edges(mixed $value): array { if(!is_array($value))return[];$out=[];foreach($value as $edge){if(!is_array($edge))continue;$from=trim((string)($edge['from']??''));$to=trim((string)($edge['to']??''));if($from!==''&&$to!==''&&strlen($from)<=190&&strlen($to)<=190)$out[]=['from'=>$from,'to'=>$to];}return$out; }
    /** @param array<int,array{from:string,to:string}> $edges @return array<int,string> */
    private static function shortestPath(array $edges,string $from,string $to): array { if($from===''||$to==='')return[];if($from===$to)return[$from];$queue=[[$from]];$seen=[$from=>true];while($queue!==[]){$path=array_shift($queue);$last=$path[count($path)-1];foreach($edges as $e){if($e['from']!==$last||isset($seen[$e['to']]))continue;$next=[...$path,$e['to']];if($e['to']===$to)return$next;$seen[$e['to']]=true;$queue[]=$next;}}return[]; }
    /** @param array<int,float> $series @return array{0:float,1:float} */
    private static function linearFit(array $series): array { $n=count($series);$sx=($n-1)*$n/2;$sy=array_sum($series);$sxx=($n-1)*$n*(2*$n-1)/6;$sxy=0.0;foreach($series as $i=>$v)$sxy+=$i*$v;$den=$n*$sxx-$sx*$sx;$slope=$den!=0?($n*$sxy-$sx*$sy)/$den:0.0;$intercept=($sy-$slope*$sx)/max(1,$n);return[$slope,$intercept]; }

    /** @param array<string,mixed> $input */
    private static function assertAggregateSafe(array $input): void
    {
        $forbidden=['password','passwd','pwd','otp','cvv','cvc','pan','card_number','secret','api_key','client_secret','private_key','access_token','refresh_token','clinical_note','prescription_body','message_body','identity_document','raw_query','email','phone','ip_address'];
        $scan=function(mixed $value,int $depth=0) use (&$scan,$forbidden):void {
            if($depth>6)throw new \InvalidArgumentException('Future feature input is too deeply nested.');
            if(!is_array($value))return;
            foreach($value as $k=>$v){$key=strtolower((string)$k;);}
        };
        $scan = function(mixed $value, int $depth = 0) use (&$scan, $forbidden): void {
            if ($depth > 6) throw new \InvalidArgumentException('Future feature input is too deeply nested.');
            if (!is_array($value)) return;
            foreach ($value as $k => $v) {
                $key = strtolower((string) $k);
                foreach ($forbidden as $fragment) if (str_contains($key, $fragment)) throw new \InvalidArgumentException('Restricted field in future feature input.');
                $scan($v, $depth + 1);
            }
        };
        $scan($input);
    }
}
