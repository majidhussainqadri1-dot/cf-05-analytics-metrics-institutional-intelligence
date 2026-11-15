<?php
declare(strict_types=1);

/**
 * CF05-FR-022/-023: report and export may not bypass the source
 * integrity boundary that governs direct API/dashboard disclosure.
 */
namespace Sabri\AnalyticsIntelligence\Domain {
    final class MetricCatalog {
        public function __construct($db) {}
        public function active(string $id,string $version):?array{
            return ['definition'=>[
                'dimensions'=>[],'dimension_policies'=>[],
                'minimum_cohort'=>20,'freshness_seconds'=>86400,
            ],'minimum_cohort'=>20,'name'=>'Verified aggregate'];
        }
    }
}
namespace Sabri\AnalyticsIntelligence\Infrastructure {
    final class AuditLogger {
        public function __construct(Database $db) {}
        public function log(string $action,string $type,?string $ref,string $result,
            array $context=[],?string $purpose=null,?string $traceId=null,
            ?int $actor=null,string $actorType='user'):bool{return true;}
    }
}
namespace {
    if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
    if(!defined('HOUR_IN_SECONDS'))define('HOUR_IN_SECONDS',3600);
    if(!defined('DAY_IN_SECONDS'))define('DAY_IN_SECONDS',86400);
    if(!defined('SMAI_PSEUDONYM_KEY'))define('SMAI_PSEUDONYM_KEY',str_repeat('z',48));
    if(!function_exists('user_can')){function user_can(int $id,string $cap):bool{return $id===5;}}
    if(!function_exists('get_option')){function get_option(string $key,mixed $d=null):mixed{return $d;}}
    if(!function_exists('wp_json_encode')){function wp_json_encode(mixed $v,int $f=0):string|false{return json_encode($v,$f);}}
    if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags(string $v):string{return strip_tags($v);}}
    if(!function_exists('is_wp_error')){function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}}
    if(!class_exists('WP_Error')){class WP_Error{
        public function __construct(public string $code,public string $message='',public array $data=[]){}
    }}
    if(!class_exists('wpdb')){class wpdb{
        public string $prefix='wp_';
        public string $last_error='';
        public mixed $reportRow=null;
        public mixed $exportRows=[];
        public int $privacyWrites=0;
        public function prepare(string $sql,mixed ...$args):string{return $sql;}
        public function get_row(string $sql,mixed $mode=null):mixed{return $this->reportRow;}
        public function get_results(string $sql,mixed $mode=null):mixed{
            if(str_contains($sql,'smai_metric_snapshots'))return $this->exportRows;
            return [];
        }
        public function query(string $sql):int {
            if(str_contains($sql,'INSERT IGNORE INTO'))$this->privacyWrites++;
            return 1;
        }
        public function get_var(string $sql):mixed{return 1;}
    }}
    spl_autoload_register(static function(string $class):void{
        $prefix='Sabri\\AnalyticsIntelligence\\';
        if(!str_starts_with($class,$prefix))return;
        $file=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require_once $file;
    });

    $sql=new wpdb();
    $db=new \Sabri\AnalyticsIntelligence\Infrastructure\Database($sql);
    $reports=new \Sabri\AnalyticsIntelligence\Domain\ReportService($db);
    $exports=new \Sabri\AnalyticsIntelligence\Domain\ExportService($db);
    $reportMethod=(new ReflectionClass(\Sabri\AnalyticsIntelligence\Domain\ReportService::class))->getMethod('latestSnapshot');
    $exportMethod=(new ReflectionClass(\Sabri\AnalyticsIntelligence\Domain\ExportService::class))->getMethod('metricRows');
    $uuid='11111111-1111-4111-8111-111111111111';
    $windowStart=gmdate('Y-m-d H:i:s',time()-7200);
    $windowEnd=gmdate('Y-m-d H:i:s',time()-3600);
    $base=[
        'metric_id'=>'education.rates','metric_version'=>'1.0.0',
        'window_start'=>$windowStart,'window_end'=>$windowEnd,
        'dimensions_json'=>'[]','value_decimal'=>'42.5',
        'numerator_decimal'=>'42.5','denominator_decimal'=>'65',
        'coverage_decimal'=>'1','cohort_size'=>'65',
        'quality_status'=>'green','data_through'=>gmdate('Y-m-d H:i:s',time()-1800),
        'caveats_json'=>'[]','uncertainty_json'=>'{}',
    ];
    $projected=[
        'metric_id'=>'education.rates','metric_version'=>'1.0.0',
        'window_start'=>$windowStart,'window_end'=>$windowEnd,
        'dimensions'=>'[]','value'=>'42.5','numerator'=>'42.5',
        'denominator'=>'65','coverage_decimal'=>'1','cohort_size'=>'65',
        'quality_status'=>'green','data_through'=>$base['data_through'],'caveats'=>'[]',
    ];
    $definition=['metric_id'=>'education.rates','metric_version'=>'1.0.0','window_start'=>null,'window_end'=>null];
    $getReport=static fn()=>$reportMethod->invoke($reports,'education.rates','1.0.0',[],$uuid,5,'approved institutional reporting');
    $getExport=static fn()=>$exportMethod->invoke($exports,$definition,10,$uuid,5,'approved institutional reporting');
    function v123_report_raises(callable $action):bool{
        try{$action();}catch(RuntimeException $e){return true;}
        return false;
    }
    $checks=[];
    $sql->reportRow=$base;
    $sql->exportRows=[$projected];
    $r=$getReport();
    $e=$getExport();
    $checks['valid report aggregate still returns measured value']=is_array($r)&&($r['value']??null)===42.5;
    $checks['valid export aggregate remains available']=count($e)===1&&($e[0]['value']??null)==='42.5';
    $checks['internal coverage evidence not added as export data column']=!array_key_exists('coverage_decimal',$e[0]);
    $checks['valid reports and exports use privacy accounting']=$sql->privacyWrites===2;

    $invalid=[
        'NaN value'=>[['value_decimal'=>'NaN'],['value'=>'NaN']],
        'corrupted numerator'=>[['numerator_decimal'=>'13junk'],['numerator'=>'13junk']],
        'corrupted denominator'=>[['denominator_decimal'=>'45junk'],['denominator'=>'45junk']],
        'invalid coverage'=>[['coverage_decimal'=>'1.5'],['coverage_decimal'=>'1.5']],
        'cohort string suffix'=>[['cohort_size'=>'65junk'],['cohort_size'=>'65junk']],
        'cohort negative'=>[['cohort_size'=>'-1'],['cohort_size'=>'-1']],
        'invalid quality'=>[['quality_status'=>'current_green'],['quality_status'=>'current_green']],
    ];
    foreach($invalid as $name=>[$reportOverride,$exportOverride]){
        $sql->reportRow=array_replace($base,$reportOverride);
        $sql->exportRows=[array_replace($projected,$exportOverride)];
        $before=$sql->privacyWrites;
        $checks['report rejects '.$name]=v123_report_raises($getReport);
        $checks['export rejects '.$name]=v123_report_raises($getExport);
        $checks['invalid '.$name.' did not consume privacy budget']=$sql->privacyWrites===$before;
    }

    $sql->reportRow=null;
    $sql->exportRows=[];
    $sql->last_error='';
    $checks['verified absent report snapshot remains absent']=$getReport()===null;
    $checks['verified empty export snapshot remains empty']=$getExport()===[];
    $sql->last_error='simulated warehouse error';
    $checks['failed report SQL is not a not-found event']=v123_report_raises($getReport);
    $sql->exportRows=false;
    $checks['failed export SQL is not an empty report']=v123_report_raises($getExport);

    $failed=0;
    foreach($checks as $label=>$ok){
        echo ($ok?'PASS':'FAIL').' V12.3 report/export '.$label.PHP_EOL;
        if(!$ok)$failed++;
    }
    echo 'CF05 V12.3 report/export numeric evidence: '.(count($checks)-$failed).'/'.count($checks).PHP_EOL;
    exit($failed===0?0:1);
}
