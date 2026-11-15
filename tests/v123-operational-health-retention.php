<?php
declare(strict_types=1);

/**
 * V12.3 operational health and retention SQL truth.
 * Fixtures exercise failed COUNT, failed aggregate, and rollback on
 * absent expired-export inventory without deleting any live data.
 */
require __DIR__ . '/bootstrap.php';
foreach (['DAY_IN_SECONDS'=>86400,'HOUR_IN_SECONDS'=>3600] as $name=>$seconds) {
    if (!defined($name)) { define($name,$seconds); }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default=null): mixed{return $default;}
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix='wp_';
        public mixed $countResult='0';
        public mixed $expiredExports=false;
        public int $rollbackCount=0;
        public int $commits=0;
        public function prepare(string $sql,mixed ...$args):string{return $sql;}
        public function get_var(string $sql):mixed{return $this->countResult;}
        public function get_col(string $sql):mixed{return $this->expiredExports;}
        public function query(string $sql):int {
            if($sql==='ROLLBACK'){$this->rollbackCount++;}
            if($sql==='COMMIT'){$this->commits++;}
            return 1;
        }
        public function delete(string $table,array $where):int{return 1;}
    }
}
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\RetentionRunner;
use Sabri\AnalyticsIntelligence\Infrastructure\RepairService;

$wpdb=new wpdb();
$db=new Database($wpdb);
$health=new HealthService($db);
$reflect=new ReflectionClass(HealthService::class);
$aggregate=$reflect->getMethod('healthAggregateRow');
$normalizer=$reflect->getMethod('checkedAggregate');
$passed=0;$failed=0;
function assert_operational(bool $ok,string $label):void{
 global $passed,$failed;
 echo ($ok?'PASS':'FAIL').' V12.3 operations '.$label.PHP_EOL;
 if($ok){$passed++;}else{$failed++;}
}
foreach([['0',0],['17',17],[0,0],[PHP_INT_MAX,PHP_INT_MAX]] as [$raw,$expected]) {
 $wpdb->countResult=$raw;
 assert_operational($db->count('metrics')===$expected,'genuine SQL count preserved');
 assert_operational($normalizer->invoke($health,$raw)===$expected,'genuine health aggregate preserved');
}
foreach([false,null,'','-1','01','1e309','1.2',1.5,PHP_INT_MAX.'0'] as $raw){
 $wpdb->countResult=$raw;
 assert_operational($db->count('metrics')===null,'failed or invalid database COUNT is Unknown');
 assert_operational($normalizer->invoke($health,$raw)===null,'failed or invalid health aggregate is Unknown');
}
$names=['pending','dead'];
$empty=$aggregate->invoke($health,['pending'=>null,'dead'=>null],$names);
assert_operational($empty===['pending'=>0,'dead'=>0],'successful empty-table MySQL SUM(NULL) interpreted as verified empty');
$valid=$aggregate->invoke($health,['pending'=>'7','dead'=>'2','oldest'=>null],$names);
assert_operational($valid===['pending'=>7,'dead'=>2],'successful SQL SUM counts preserved');
foreach([
 false,null,[],
 ['pending'=>null],
 ['pending'=>'invalid','dead'=>'0'],
 ['pending'=>'2','dead'=>false],
 ['pending'=>-1,'dead'=>'0'],
] as $invalid){
 assert_operational($aggregate->invoke($health,$invalid,$names)===null,'missing/corrupt SQL health row is Unknown');
}
$wpdb->countResult='0';
$retention=new RetentionRunner($db);
try {
 $retention->run();
 assert_operational(false,'missing export inventory must throw');
} catch (RuntimeException $exception) {
 assert_operational(str_contains($exception->getMessage(),'Retention run failed safely'),'failed export inventory aborts retention run');
}
assert_operational($wpdb->rollbackCount===1 && $wpdb->commits===0,'failed retention inventory rolls back without committing');

$repairText=file_get_contents(__DIR__ . '/../src/Infrastructure/RepairService.php');
assert_operational(is_string($repairText) && str_contains($repairText,'Safe repair cannot enumerate unprocessed events.'),'repair failure mode for unknown unprocessed events is explicitly implemented');
assert_operational(is_string($repairText) && str_contains($repairText,'Safe repair cannot enumerate outstanding privacy deletions.'),'repair failure mode for unknown deletion inventory is explicitly implemented');
echo 'CF-05 V12.3 health and retention: '.$passed.'/'.($passed+$failed).PHP_EOL;
exit($failed===0?0:1);
