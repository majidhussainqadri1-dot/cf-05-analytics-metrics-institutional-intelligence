<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!class_exists('WP_Error')) {
 class WP_Error {
  public function __construct(public string $code,public string $message='',public array $data=[]) {}
  public function get_error_code():string{return $this->code;}
 }
}
if (!class_exists('wpdb')) {
 class wpdb {
  public string $prefix='wp_';
  public array $stored=['job_uuid'=>'11111111-1111-4111-8111-111111111111','state'=>'queued','job_type'=>'quality.run','payload_json'=>'{"sample":1}','max_attempts'=>'5'];
  public function prepare(string $s,mixed ...$args):string{return $s;}
  public function query(string $sql):int{return 0;}
  public function get_row(string $sql,mixed $format=null):array{return $this->stored;}
 }
}
use Sabri\AnalyticsIntelligence\Domain\PrivacyQueryPolicy;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
$failed=0;
function sr430(bool $ok,string $name):void {
 global $failed;
 echo ($ok?'PASS':'FAIL').': '.$name.PHP_EOL;
 if (!$ok) $failed++;
}
$before=['window_start'=>'2026-10-01 00:00:00','window_end'=>'2026-10-02 00:00:00',
 'dimension_names'=>['region'],'dimension_hashes'=>['region'=>'abc'],'cohort_size'=>100];
$after=$before;
sr430(!PrivacyQueryPolicy::differencingRisk($before,$before,20),'Exact slice with unchanged cohort remains repeatable');
$after['cohort_size']=99;
sr430(PrivacyQueryPolicy::differencingRisk($after,$before,20),'One-person same-slice cohort change blocked');
$after['cohort_size']=81;
sr430(PrivacyQueryPolicy::differencingRisk($after,$before,20),'Nineteen-person same-slice delta blocked');
$after['cohort_size']=80;
sr430(!PrivacyQueryPolicy::differencingRisk($after,$before,20),'Cohort delta at approved floor not falsely blocked');
$wpdb=new wpdb();$queue=new JobQueue(new Database($wpdb));
$ok=$queue->enqueue('quality.run',['sample'=>1],'duplicate-key-123456');
sr430(is_array($ok)&&($ok['duplicate']??false),'Genuine identical queued job reused');
$bad=$queue->enqueue('backfill.run',['sample'=>1],'duplicate-key-123456');
sr430($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict','Key collision with different job type refused');
$bad=$queue->enqueue('quality.run',['sample'=>999],'duplicate-key-123456');
sr430($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict','Key collision with different payload refused');
$wpdb->stored['payload_json']='{broken';
$bad=$queue->enqueue('quality.run',['sample'=>1],'duplicate-key-123456');
sr430($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict','Corrupted queued payload refused');
$wpdb->stored['payload_json']='{"sample":1}';
unset($wpdb->stored['job_type']);
$bad=$queue->enqueue('quality.run',['sample'=>1],'duplicate-key-123456');
sr430($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict','Missing stored job type refused');
echo 'SR430–469 adversarial assertions: '.(9-$failed).'/9'.PHP_EOL;
exit($failed===0?0:1);
