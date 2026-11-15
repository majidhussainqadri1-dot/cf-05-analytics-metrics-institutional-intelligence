<?php
declare(strict_types=1);

/**
 * CF05-FR-022 V12.3: scheduled reports cannot treat SQL failure as no work.
 * All authentication/runtime states and SQL rows are synthetic fixtures.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) {define('ARRAY_A','ARRAY_A');}
if (!defined('SMAI_SCHEMA_VERSION')) {define('SMAI_SCHEMA_VERSION','1.4.1');}
if (!defined('SMAI_ACTIVATION_EVIDENCE_SHA256')) {define('SMAI_ACTIVATION_EVIDENCE_SHA256',str_repeat('a',64));}
foreach(['SMAI_INGESTION_SECRET','SMAI_PSEUDONYM_KEY','SMAI_EXPORT_KEY'] as $constant){
    if(!defined($constant)){define($constant,str_repeat('z',48));}
}
$GLOBALS['smai_test_workers_enabled']='1';
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $fallback = null): mixed {
        if($name==='smai_schema_version'){return '1.4.1';}
        if($name==='smai_schema_migration_error'){return null;}
        if($name==='smai_runtime_state'){return 'staging_active';}
        if($name==='smai_activation_evidence_hash'){return str_repeat('a',64);}
        if($name==='smai_activation_approved'){return '1';}
        if($name==='smai_worker_enabled'){return $GLOBALS['smai_test_workers_enabled'];}
        return $fallback;
    }
}
if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type():string {return 'staging';}
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix='wp_';
        public mixed $result=false;
        public int $calls=0;
        public function prepare(string $sql,mixed ...$args):string{return $sql;}
        public function get_results(string $sql,mixed $format=null):mixed{
            $this->calls++;
            return $this->result;
        }
    }
}
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
$wpdb=new wpdb();
$service=new ReportService(new Database($wpdb));
$failed=0;$passed=0;
function expect_report(bool $ok,string $label):void{
 global $failed,$passed;
 echo ($ok?'PASS':'FAIL').' V12.3 scheduling '.$label.PHP_EOL;
 $ok?$passed++:$failed++;
}
try{$service->scheduleDue();expect_report(false,'failed SQL due-list was rejected');}
catch(RuntimeException $e){expect_report(str_contains($e->getMessage(),'Scheduled report inventory'),'failed SQL due-list throws explicit error');}
$wpdb->result=[];
expect_report($service->scheduleDue()===0,'verified no-due-reports is zero');
$GLOBALS['smai_test_workers_enabled']='0';
$wpdb->result=false;$before=$wpdb->calls;
expect_report($service->scheduleDue()===0 && $wpdb->calls===$before,'deliberately inactive worker avoids report SQL');
echo 'CF-05 V12.3 scheduled-report evidence: '.$passed.'/'.($passed+$failed).PHP_EOL;
exit($failed===0?0:1);
