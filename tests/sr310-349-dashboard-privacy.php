<?php
declare(strict_types=1);
$root = getenv('CF05_SOURCE_ROOT') ?: dirname(__DIR__);
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS',3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS',86400);
if (!defined('SMAI_PSEUDONYM_KEY')) define('SMAI_PSEUDONYM_KEY',str_repeat('s',48));
if (!function_exists('user_can')) { function user_can(int $u,string $c):bool{return $u===5;} }
if (!function_exists('wp_json_encode')) { function wp_json_encode(mixed $x,int $f=0):string|false{return json_encode($x,$f);} }
if (!function_exists('get_option')) { function get_option(string $k,mixed $d=null):mixed{return $d;} }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags(string $s):string{return strip_tags($s);} }
if (!function_exists('is_wp_error')) { function is_wp_error(mixed $o):bool{return $o instanceof WP_Error;} }
if (!class_exists('WP_Error')) {
 class WP_Error {public function __construct(public string $code,public string $message,public array $data=[]){} public function get_error_code():string{return $this->code;}}
}
class wpdb {
 public string $prefix='wp_';public string $scenario='';public int $inserts=0;
 public function prepare(string $sql,mixed ...$args):string{return $sql;}
 public function query(string $sql):int|false{$this->inserts+=str_contains($sql,'INSERT IGNORE INTO')?1:0;return 1;}
 public function get_var(string $sql):mixed{return 1;}
 public function get_row(string $sql,mixed $format=null):?array {
   if(str_contains($sql,'dashboard_definitions'))return ['dashboard_id'=>'sample.dashboard','dashboard_version'=>'1.0.0','state'=>'active','expires_at'=>null,'audience_json'=>'{"user_ids":[5]}','name'=>'Sample','project_uuid'=>'11111111-1111-4111-8111-111111111111','owner_user_id'=>5];
   if(str_contains($sql,'access_projects'))return ['id'=>4,'state'=>'active','expires_at'=>'2030-01-01 00:00:00','owner_user_id'=>5,'purpose'=>'approved institutional aggregate reporting'];
   if(str_contains($sql,'audit_state'))return ['last_hash'=>str_repeat('0',64),'row_version'=>1];
   return null;
 }
 public function get_results(string $sql,mixed $format=null):mixed {
   if($this->scenario==='dashboard_read_failure' && str_contains($sql,'dashboard_widgets'))return false;
   if($this->scenario==='corrupt_history' && str_contains($sql,"scope='privacy-query'"))return [[
     'response_json'=>json_encode(['slice_fingerprint'=>str_repeat('a',64),'cohort_size'=>66,'privacy_cost'=>1]),
     'request_hash'=>str_repeat('a',64),'created_at'=>'2026-10-21 11:00:00'
   ]];
   return [];
 }
 public function insert(string $table,array $data):int{return 1;}
 public function update(string $table,array $data,array $where):int{return 1;}
}
spl_autoload_register(static function(string $name)use($root):void{
 $prefix='Sabri\\AnalyticsIntelligence\\';
 if(str_starts_with($name,$prefix)){
  $p=$root.'/src/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
  if(file_exists($p))require_once $p;
 }
});
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Domain\DashboardService;
use Sabri\AnalyticsIntelligence\Domain\QueryPrivacyGuard;
$failures=0;
function review310(bool $passed,string $label):void{global $failures;echo ($passed?'PASS':'FAIL').': '.$label."\n";if(!$passed)$failures++;}
$db=new wpdb();$db->scenario='dashboard_read_failure';
$svc=new DashboardService(new Database($db));
$result=$svc->bundle('sample.dashboard','1.0.0',5);
review310($result instanceof WP_Error && $result->get_error_code()==='smai_dashboard_widgets_unavailable','Dashboard widget SQL failure withheld');
$reflect=new ReflectionMethod(DashboardService::class,'storedWidgetsValidForActivation');
$privateResult=$reflect->invoke($svc,['dashboard_id'=>'sample.dashboard','dashboard_version'=>'1.0.0','project_uuid'=>'11111111-1111-4111-8111-111111111111']);
review310($privateResult instanceof WP_Error && $privateResult->get_error_code()==='smai_dashboard_widgets_unavailable','Dashboard activation blocked on SQL error');
$emptySvc=new DashboardService(new Database(new wpdb()));
$emptyResult=$reflect->invoke($emptySvc,['dashboard_id'=>'sample.dashboard','dashboard_version'=>'1.0.0','project_uuid'=>'11111111-1111-4111-8111-111111111111']);
review310($emptyResult instanceof WP_Error && $emptyResult->get_error_code()==='smai_dashboard_widgets_missing','Dashboard activation blocked on empty widgets');
$db2=new wpdb();$db2->scenario='corrupt_history';
$guard=new QueryPrivacyGuard(new Database($db2));
$privacy=$guard->evaluateAndRecord('education.completion','1.0.0','11111111-1111-4111-8111-111111111111',5,'approved institutional aggregate reporting','2026-10-01 00:00:00','2026-10-02 00:00:00',[],['minimum_cohort'=>20],66,20);
review310($privacy instanceof WP_Error && $privacy->get_error_code()==='smai_privacy_evidence_unavailable','Incomplete old privacy witness rejected');
review310($db2->inserts===0,'Corrupt old witness cannot create allow decision');
echo 'SR310-349 behavior regressions: '.(5-$failures)."/5\n";
exit($failures?1:0);
