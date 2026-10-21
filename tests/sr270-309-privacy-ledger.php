<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('SMAI_PSEUDONYM_KEY')) define('SMAI_PSEUDONYM_KEY', 'ssssssssssssssssssssssssssssssssssssssss');
if (!class_exists('WP_Error')) {
 class WP_Error {
  public function __construct(public string $code,public string $message,public array $data=[]){}
  public function get_error_code():string{return $this->code;}
 }
}
if (!class_exists('wpdb')) {
 class wpdb {
  public string $prefix='wp_';
  public bool $readFailure=false,$corruptLedger=false,$inserted=false,$collision=false;
  public ?string $oldKey=null,$requestHash=null;
  public function prepare(string $sql,mixed ...$args):string {return $sql.'--ARGS--'.base64_encode(json_encode($args));}
  public function get_var(string $sql):mixed {
   if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1;
   if(str_contains($sql,'SELECT request_hash'))return $this->requestHash;
   return null;
  }
  public function get_results(string $sql,mixed $format=null):mixed {
   return $this->readFailure?false:($this->corruptLedger?[['response_json'=>'{broken','request_hash'=>'bad']]:[]);
  }
  public function query(string $sql):int|false {
   if(!str_contains($sql,'INSERT IGNORE INTO')||!str_contains($sql,'privacy-query'))return false;
   $args=json_decode(base64_decode(explode('--ARGS--',$sql)[1]??''),true);
   $key=(string)($args[0]??'');$this->requestHash=(string)($args[2]??'');
   if($key===$this->oldKey){$this->collision=true;return 0;}
   $this->inserted=true;return 1;
  }
 }
}
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Domain\QueryPrivacyGuard;
$definition=['minimum_cohort'=>20,'privacy_controls'=>['max_dimensions_per_query'=>4]];
$query=static fn(wpdb $db)=>(new QueryPrivacyGuard(new Database($db)))->evaluateAndRecord(
 'education.rate','1.0.0','aaa11111-1111-4111-8111-111111111111',3,
 'approved institutional measurement','2026-10-01 00:00:00','2026-10-02 00:00:00',
 [],$definition,60,20
);
$failures=0;
$check=static function(bool $ok,string $name)use(&$failures):void{
 echo ($ok?'PASS: ':'FAIL: ').$name.PHP_EOL;if(!$ok)$failures++;
};
$db=new wpdb();$db->readFailure=true;$v=$query($db);
$check($v instanceof WP_Error && $v->get_error_code()==='smai_privacy_evidence_unavailable','SQL read failure fails closed');
$db=new wpdb();$db->corruptLedger=true;$v=$query($db);
$check($v instanceof WP_Error && $v->get_error_code()==='smai_privacy_evidence_unavailable','Corrupted historical privacy ledger fails closed');
$db=new wpdb();$v=$query($db);
$check($v===true&&$db->inserted,'Healthy query persists privacy evidence');
$db=new wpdb();$actorRef=hash_hmac('sha256','query|project|aaa11111-1111-4111-8111-111111111111|education.rate@1.0.0',SMAI_PSEUDONYM_KEY);
$requestHash=hash_hmac('sha256',\Sabri\AnalyticsIntelligence\Infrastructure\Json::canonical([
 'metric_id'=>'education.rate','metric_version'=>'1.0.0',
 'project_uuid'=>'aaa11111-1111-4111-8111-111111111111',
 'window_start'=>'2026-10-01 00:00:00','window_end'=>'2026-10-02 00:00:00','dimensions'=>[],
]),SMAI_PSEUDONYM_KEY);
$db->oldKey=hash_hmac('sha256','privacy-query|'.$actorRef.'|'.$requestHash,SMAI_PSEUDONYM_KEY);
$v=$query($db);
$check($v===true&&$db->inserted&&!$db->collision,'Expired prior-hour key cannot suppress fresh evidence');
echo "CF05 SR270-309 privacy-ledger regression: ".(4-$failures)."/4\n";
exit($failures?1:0);
