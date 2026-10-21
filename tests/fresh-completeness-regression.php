<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('SMAI_EXPORT_KEY')) define('SMAI_EXPORT_KEY', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
if (!function_exists('user_can')) { function user_can(int $id,string $cap):bool { return false; } }
if (!class_exists('wpdb')) {
 class wpdb {
  public string $prefix='wp_'; public array $queries=[]; public ?array $row=null;
  public function query(string $sql):bool {$this->queries[]=$sql;return false;}
  public function prepare(string $sql,mixed ...$args):string{return $sql;}
  public function get_row(string $sql,mixed $format=null):?array{$this->queries[]=$sql;return $this->row;}
 }
}
use Sabri\AnalyticsIntelligence\Domain\FunnelAttributionCalculator;
use Sabri\AnalyticsIntelligence\Domain\TransformationEngine;
use Sabri\AnalyticsIntelligence\Domain\SnapshotService;
use Sabri\AnalyticsIntelligence\Domain\QualityService;
use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Domain\ExportService;
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
$failed=0;
function check190(bool $condition,string $label):void {global $failed;echo ($condition?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$condition)$failed++;}
$transform=new TransformationEngine();$denied=false;
try{$transform->map(['properties'=>['date'=>'tomorrow']],['date'=>['from'=>'properties.date','type'=>'timestamp']]);}
catch(\RuntimeException $e){$denied=str_contains($e->getMessage(),'normalization failed');}
check190($denied,'relative dataset time rejected');
$good=$transform->map(['properties'=>['date'=>'2026-08-01T09:00:00Z']],['date'=>['from'=>'properties.date','type'=>'timestamp']]);
check190(($good['date']??null)==='2026-08-01T09:00:00+00:00','canonical source time accepted');
$spec=['subject_field'=>'subject','step_field'=>'stage','time_field'=>'time','eligible_field'=>'eligible','channel_field'=>'channel','entry_step'=>'entry','conversion_step'=>'converted','window_seconds'=>200000,'attribution_policy'=>'first_touch'];
$row=static fn(string $id,string $stage,string $time,string $channel):array=>['subject'=>hash('sha256',$id),'stage'=>$stage,'time'=>$time,'channel'=>$channel,'eligible'=>true];
[$rate,$n,$d]=FunnelAttributionCalculator::calculate([$row('x','entry','today','direct'),$row('x','converted','tomorrow','search')],$spec);
check190($d===0.0&&$n===0.0,'relative funnel event rejected');
$tied=[$row('u','entry','2026-08-01T09:00:00Z','search'),$row('u','entry','2026-08-01T09:00:00Z','direct'),$row('u','converted','2026-08-01T10:00:00Z','direct')];
[$v,$n1,$d1]=FunnelAttributionCalculator::calculate($tied,$spec,'search');
[$v,$n2,$d2]=FunnelAttributionCalculator::calculate(array_reverse($tied),$spec,'search');
check190($n1===0.0&&$n2===0.0&&$d1===0.0&&$d2===0.0,'ambiguous simultaneous first touches fail closed');
$w=new wpdb();$e='';
try{(new SnapshotService(new Database($w)))->runJob(['metric_id'=>'education.completion','metric_version'=>'1.0.0','actor_user_id'=>5,'window_start'=>'2026-08-01T00:00:00Z','window_end'=>'2026-08-02T00:00:00Z']);}
catch(\Throwable $x){$e=$x->getMessage();}
check190(str_contains($e,'not authorized')&&count($w->queries)===0,'revoked snapshot requester denied');
$w=new wpdb();$e='';
try{(new QualityService(new Database($w)))->runJob(['dataset_ref'=>'education.events@1.0.0','actor_user_id'=>5]);}
catch(\Throwable $x){$e=$x->getMessage();}
check190(str_contains($e,'not authorized')&&count($w->queries)===0,'revoked quality requester denied');
$w=new wpdb();$e='';
try{(new BackfillService(new Database($w)))->runJob(['backfill_uuid'=>'11111111-1111-4111-8111-111111111111','actor_user_id'=>5]);}
catch(\Throwable $x){$e=$x->getMessage();}
check190(str_contains($e,'not authorized')&&count($w->queries)===0,'revoked backfill approver denied');
$w=new wpdb();$w->row=['expires_at'=>'2030-01-01 00:00:00','requester_user_id'=>5,'state'=>'requested'];$e='';
try{(new ExportService(new Database($w)))->runJob(['export_uuid'=>'11111111-1111-4111-8111-111111111111']);}
catch(\Throwable $x){$e=$x->getMessage();}
check190(str_contains($e,'not authorized')&&count($w->queries)===1,'revoked export requester denied');
$w=new wpdb();$w->row=['owner_user_id'=>5,'expires_at'=>null,'state'=>'active'];$e='';
try{(new ReportService(new Database($w)))->runJob(['report_uuid'=>'11111111-1111-4111-8111-111111111111']);}
catch(\Throwable $x){$e=$x->getMessage();}
check190(str_contains($e,'not authorized')&&count($w->queries)===1,'revoked scheduled-report owner denied');
echo "CF-05 SR190-229 regression failures: {$failed}\n";exit($failed===0?0:1);
