<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use WP_Error;

final class SnapshotService
{
    private Database $db;
    private LineageService $lineage;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->lineage = new LineageService($db);
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $dimensions */
    public function enqueue(string $metricId, string $version, string $start, string $end, array $dimensions, int $actorUserId): array|WP_Error
    {
        $startTs = strtotime($start); $endTs = strtotime($end);
        if ($actorUserId < 1 || $startTs === false || $endTs === false || $startTs >= $endTs || ($endTs - $startTs) > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_invalid_snapshot_window', 'Snapshot window is invalid.', ['status' => 400]);
        }
        ksort($dimensions);
        return (new JobQueue($this->db))->enqueue('snapshot.compute', [
            'metric_id'=>$metricId,'metric_version'=>$version,'window_start'=>gmdate('c',$startTs),'window_end'=>gmdate('c',$endTs),'dimensions'=>$dimensions,'actor_user_id'=>$actorUserId,
        ], 'snapshot|' . $metricId . '|' . $version . '|' . gmdate('c',$startTs) . '|' . gmdate('c',$endTs) . '|' . hash('sha256', Json::canonical($dimensions)));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $metricId=(string)($payload['metric_id']??'');$version=(string)($payload['metric_version']??'');
        $start=$this->date((string)($payload['window_start']??''));$end=$this->date((string)($payload['window_end']??''));
        $dimensions=is_array($payload['dimensions']??null)?$payload['dimensions']:[];
        if($start>=$end){throw new \InvalidArgumentException('Invalid snapshot window.');}
        $metric=(new MetricCatalog($this->db))->active($metricId,$version);if($metric===null){throw new \RuntimeException('Metric is not active.');}
        $definition=(array)$metric['definition'];$source=is_array($definition['source']??null)?$definition['source']:[];
        $datasetId=(string)($source['dataset_id']??'');$datasetVersion=(string)($source['dataset_version']??'');
        $dataset=(new DatasetCatalog($this->db))->published($datasetId,$datasetVersion);if($dataset===null){throw new \RuntimeException('Metric source dataset is not published.');}
        $allowed=array_map('strval',(array)($definition['dimensions']??[]));
        foreach(array_keys($dimensions) as $key){if(!in_array((string)$key,$allowed,true)){throw new \RuntimeException('Unapproved snapshot dimension.');}}
        $build=$this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('dataset_builds')}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 LIMIT 1",$datasetId,$datasetVersion),ARRAY_A);
        if(!is_array($build)){throw new \RuntimeException('No active dataset build.');}
        $semantics=(string)($definition['historical_semantics']??'current');
        $currentClause=$semantics==='as_occurred'?'':" AND is_current=1";
        $stored=$this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT row_json,effective_from,is_current FROM `{$this->db->table('dataset_rows')}` WHERE build_uuid=%s AND effective_from>=%s AND effective_from<%s{$currentClause}",$build['build_uuid'],$start,$end),ARRAY_A);
        $rows=[];$dataThrough=null;
        foreach(is_array($stored)?$stored:[] as $item){$row=Json::object((string)$item['row_json']);if(!$this->dimensionMatch($row,$dimensions)){continue;}$rows[]=$row;if($dataThrough===null||(string)$item['effective_from']>$dataThrough){$dataThrough=(string)$item['effective_from'];}}
        $calculation=is_array($definition['calculation']??null)?$definition['calculation']:['type'=>'count'];
        [$value,$numerator,$denominator]=$this->calculate($calculation,$rows);
        $cohortField=(string)($definition['cohort_field']??'');$cohort=count($rows);
        if($cohortField!==''){$set=[];foreach($rows as $row){$v=$row[$cohortField]??null;if($v!==null&&$v!==''){$set[Json::canonical($v)]=true;}}$cohort=count($set);}
        $minimum=max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20));
        $quality=(string)$dataset['quality_status'];$caveats=[];
        if($dataThrough===null){$quality='unknown';$caveats[]='No eligible source data was available for the requested window.';}
        $maxFreshness=max(60,(int)($definition['freshness_seconds']??86400));
        if($dataThrough!==null&&time()-(int)strtotime($dataThrough)>$maxFreshness){$quality='stale';$caveats[]='Data is older than the declared freshness threshold.';}
        if(!in_array($quality,['green','amber','red','unknown','stale'],true)){$quality='unknown';}
        if($cohort<$minimum){$quality='suppressed';$value=$numerator=$denominator=null;$caveats[]='Result suppressed below minimum cohort.';}
        if($dataset['quality_status']!=='green'){$caveats[]='Dataset quality is not green.';}
        $uncertainty=null;if(($calculation['type']??'')==='ratio'&&$numerator!==null&&$denominator!==null&&$denominator>0){$uncertainty=Statistics::proportionInterval((int)round($numerator),(int)round($denominator));}
        ksort($dimensions);$dimensionsJson=Json::canonical($dimensions);$dimensionsHash=hash('sha256',$dimensionsJson);
        $base=[
            'metric_id'=>$metricId,'metric_version'=>$version,'build_uuid'=>$build['build_uuid'],'window_start'=>$start,'window_end'=>$end,'dimensions_hash'=>$dimensionsHash,
            'dimensions_json'=>$dimensionsJson,'value_decimal'=>$value,'numerator_decimal'=>$numerator,'denominator_decimal'=>$denominator,'cohort_size'=>$cohort,
            'quality_status'=>$quality,'data_through'=>$dataThrough,'coverage_decimal'=>$this->coverage($cohort,$minimum,$quality),'uncertainty_json'=>$uncertainty===null?null:Json::encode($uncertainty),
            'caveats_json'=>Json::encode(array_values(array_unique($caveats))),'state'=>'published','created_at'=>$this->db->now(),
        ];
        $table=$this->db->table('metric_snapshots');$wpdb=$this->db->wpdb();
        $previous=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE metric_id=%s AND metric_version=%s AND window_start=%s AND window_end=%s AND dimensions_hash=%s ORDER BY snapshot_revision DESC LIMIT 1",$metricId,$version,$start,$end,$dimensionsHash),ARRAY_A);
        $revision=is_array($previous)?(int)$previous['snapshot_revision']+1:1;
        $semantic=$base;unset($semantic['created_at']);
        $candidateHash=hash('sha256',Json::canonical($semantic));
        if(is_array($previous)&&hash_equals((string)$previous['snapshot_hash'],$candidateHash)){
            return ['snapshot_id'=>(int)$previous['id'],'snapshot_revision'=>(int)$previous['snapshot_revision'],'snapshot_hash'=>$previous['snapshot_hash'],'quality_status'=>$previous['quality_status'],'cohort_size'=>(int)$previous['cohort_size'],'value'=>$previous['value_decimal']!==null?(float)$previous['value_decimal']:null,'unchanged'=>true];
        }
        $record=array_merge($base,['snapshot_revision'=>$revision,'supersedes_snapshot_id'=>is_array($previous)?(int)$previous['id']:null,'snapshot_hash'=>$candidateHash]);
        $wpdb->query('START TRANSACTION');
        try{
            if(is_array($previous)&&$wpdb->update($table,['state'=>'superseded'],['id'=>(int)$previous['id'],'state'=>(string)$previous['state']])===false){throw new \RuntimeException('Previous snapshot could not be superseded.');}
            if($wpdb->insert($table,$record)!==1){throw new \RuntimeException('Metric snapshot could not be stored.');}
            $snapshotId=(int)$wpdb->insert_id;$wpdb->query('COMMIT');
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        $this->lineage->link('build',(string)$build['build_uuid'],null,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$dataset['owner_module'],null,defined('SMAI_CODE_SHA')?SMAI_CODE_SHA:null);
        $this->lineage->link('metric',$metricId,$version,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$metric['owner_module']);
        $this->audit->log('metric_snapshot_published','metric_snapshot',(string)$snapshotId,'success',['metric_id'=>$metricId,'metric_version'=>$version,'revision'=>$revision,'quality_status'=>$quality,'cohort_bucket'=>$this->bucket($cohort)],'institutional_measurement',null,(int)($payload['actor_user_id']??0));
        return ['snapshot_id'=>$snapshotId,'snapshot_revision'=>$revision,'snapshot_hash'=>$candidateHash,'quality_status'=>$quality,'cohort_size'=>$cohort,'value'=>$value,'unchanged'=>false];
    }

    /** @param array<string,mixed> $calculation @param array<int,array<string,mixed>> $rows */
    private function calculate(array $calculation,array $rows):array
    {
        $type=(string)($calculation['type']??'count');
        if($type==='ratio'){$numFilters=is_array($calculation['numerator_filters']??null)?$calculation['numerator_filters']:[];$denFilters=is_array($calculation['denominator_filters']??null)?$calculation['denominator_filters']:[];$num=0;$den=0;
            foreach($rows as $row){$eligible=FilterEvaluator::matches($row,$denFilters);if($eligible){$den++;if(FilterEvaluator::matches($row,$numFilters)){$num++;}}}
            return[$den>0?$num/$den:null,(float)$num,(float)$den];}
        if(in_array($type,['sum','average'],true)){$field=(string)($calculation['field']??'');$filters=is_array($calculation['filters']??null)?$calculation['filters']:[];$values=[];foreach($rows as $row){if(FilterEvaluator::matches($row,$filters)&&is_numeric($row[$field]??null)){$values[]=(float)$row[$field];}}$sum=array_sum($values);return[$type==='average'?Statistics::mean($values):$sum,$sum,(float)count($values)];}
        $filters=is_array($calculation['filters']??null)?$calculation['filters']:[];$count=0;foreach($rows as $row){if(FilterEvaluator::matches($row,$filters)){$count++;}}return[(float)$count,(float)$count,null];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $dimensions */
    private function dimensionMatch(array $row,array $dimensions):bool{foreach($dimensions as $key=>$value){if(($row[$key]??null)!==$value){return false;}}return true;}
    private function coverage(int $cohort,int $minimum,string $quality):float{if($quality==='suppressed'){return 0.0;}return min(1.0,$cohort/max(1,$minimum));}
    private function bucket(int $count):string{return match(true){$count<20=>'<20',$count<100=>'20-99',$count<1000=>'100-999',default=>'1000+'};}
    private function date(string $value):string{$timestamp=strtotime($value);if($timestamp===false){throw new \InvalidArgumentException('Invalid date.');}return gmdate('Y-m-d H:i:s',$timestamp);}
}
