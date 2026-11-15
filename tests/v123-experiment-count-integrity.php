<?php
declare(strict_types=1);

/**
 * CF05-FR-025/028 V12.3 experiment evidence: variant sizes must originate
 * from successful, bounded SQL aggregates; a failed read is not zero users.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) {define('ARRAY_A','ARRAY_A');}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public mixed $rows = [];
        public function prepare(string $sql, mixed ...$args): string{return $sql;}
        public function get_results(string $sql, mixed $format = null): mixed{return $this->rows;}
    }
}
use Sabri\AnalyticsIntelligence\Domain\ExperimentService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$wpdb = new wpdb();
$svc = new ExperimentService(new Database($wpdb));
$counts = (new ReflectionClass(ExperimentService::class))->getMethod('variantCounts');
$pass=0;$fail=0;
function check_variant(bool $ok,string $label):void{
 global $pass,$fail;
 echo ($ok?'PASS':'FAIL').' V12.3 experiment counts '.$label.PHP_EOL;
 if($ok){$pass++;}else{$fail++;}
}
function rejects_variant(callable $work):bool{
 try{$work();}catch(RuntimeException $e){return true;}
 return false;
}
$read = static fn()=>$counts->invoke($svc,'11111111-1111-4111-8111-111111111111');
$wpdb->rows=false;
check_variant(rejects_variant($read),'failed SQL participant count rejected');
$wpdb->rows=null;
check_variant(rejects_variant($read),'null SQL participant count rejected');
$wpdb->rows=[];
check_variant($read()===[],'successful empty experiment returns no groups');
$wpdb->rows=[['variant_key'=>'control','total'=>'18'],['variant_key'=>'treatment','total'=>'22']];
check_variant($read()===['control'=>18,'treatment'=>22],'verified groups preserve exact sizes');
foreach([false,null,'garbage','-1','1.4','1e309','01',1.4,PHP_INT_MAX.'0']as $bad){
 $wpdb->rows=[['variant_key'=>'control','total'=>$bad]];
 check_variant(rejects_variant($read),'invalid group COUNT '.var_export($bad,true).' denied');
}
$wpdb->rows=[['variant_key'=>'control','total'=>'5'],['variant_key'=>'control','total'=>'6']];
check_variant(rejects_variant($read),'duplicate group evidence denied');
$wpdb->rows=[['variant_key'=>'','total'=>'5']];
check_variant(rejects_variant($read),'empty variant identity denied');
echo 'CF-05 V12.3 variant evidence: '.$pass.'/'.($pass+$fail).PHP_EOL;
exit($fail===0?0:1);
