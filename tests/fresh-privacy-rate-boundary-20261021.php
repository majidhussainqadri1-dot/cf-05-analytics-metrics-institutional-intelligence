<?php
declare(strict_types=1);
/** Fresh 2026-10-21 privacy and atomic-limit regressions, SYNTHETIC only. */
require __DIR__.'/bootstrap.php';

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\RateLimiter;
use Sabri\AnalyticsIntelligence\Domain\EventIngestionService;

class wpdb {
    public string $prefix='wp_';
    public mixed $count='1';
    public mixed $write=1;
    public int $reads=0;
    public function prepare(string $sql, mixed ...$args):string { return $sql; }
    public function query(string $sql):mixed { return $this->write; }
    public function get_var(string $sql):mixed { $this->reads++; return $this->count; }
}
$wpdb=new wpdb();
$limiter=new RateLimiter(new Database($wpdb));
$failed=[];$checks=0;
$test=static function(string $title,callable $execute)use(&$failed,&$checks):void {
    $checks++;
    try { if($execute()!==true) throw new RuntimeException('Unexpected result'); }
    catch(Throwable $e){$failed[]="$checks $title: ".$e->getMessage();}
};

foreach([
 ['valid first count',1,1,true],
 ['valid numeric string','1',1,true],
 ['at exact ceiling','5',1,true],
 ['above ceiling','6',1,false],
 ['failed SELECT null',null,1,false],
 ['failed SELECT false',false,1,false],
 ['invalid SELECT empty','',1,false],
 ['impossible zero','0',1,false],
 ['negative counter','-1',1,false],
 ['non-numeric counter','oops',1,false],
 ['overflow counter',str_repeat('9',20),1,false],
 ['failed atomic insert',1,false,false],
] as [$name,$value,$write,$expected]) {
    $test($name,static function()use($wpdb,$limiter,$value,$write,$expected):bool{
        $wpdb->count=$value;$wpdb->write=$write;$wpdb->reads=0;
        $value=$limiter->consume('synthetic','sample',5,900);
        return $value===$expected && ($write!==false || $wpdb->reads===0);
    });
}
$method=(new ReflectionClass(EventIngestionService::class))->getMethod('safeEvidenceIdentifier');
$test('canonical event identifier retained',static fn():bool=>$method->invoke(null,'11111111-1111-4111-8111-111111111111','uuid')==='11111111-1111-4111-8111-111111111111');
$test('invalid identifier with email withheld',static fn():bool=>$method->invoke(null,'patient@example.com','uuid')===null);
$test('private event name withheld',static fn():bool=>$method->invoke(null,'patient@example.com','symbol')===null);
$test('malformed source module withheld',static fn():bool=>$method->invoke(null,'patient Jane Smith','module')===null);
$test('valid version retained',static fn():bool=>$method->invoke(null,'1.4.0','version')==='1.4.0');
$test('object masquerading as ID withheld',static fn():bool=>$method->invoke(null,(object)['name'=>'patient'],'uuid')===null);
$test('unsupported identity class denied',static fn():bool=>$method->invoke(null,'sample','secret')===null);
$test('quarantine stores count-only metadata, never field names',static function():bool{
    $s=file_get_contents(dirname(__DIR__).'/src/Domain/EventIngestionService.php');
    $part=explode('private function quarantine',$s,2)[1]??'';
    $part=explode('private static function safeEvidenceIdentifier',$part,2)[0]??$part;
    return str_contains($part,"'field_names_redacted' => true")
        && str_contains($part,"'reason_detail' => 'untrusted_detail_redacted'")
        && !str_contains($part,"'keys' => array_slice")
        && !str_contains($part,"'property_keys' => array_slice");
});
printf("CF-05 new boundary suite: %d/%d passed\n",$checks-count($failed),$checks);
foreach($failed as $error){fwrite(STDERR,"FAIL ".$error."\n");}
exit($failed===[]?0:1);
