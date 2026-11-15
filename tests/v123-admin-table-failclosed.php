<?php
declare(strict_types=1);

/**
 * V12.3 administrative safety: no fatal missing presenter and never expose
 * failed SQL reads as success, empty facts, or zero published records.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public bool $schemaAvailable = true;
        public mixed $count = '0';
        public mixed $rows = [];
        public function prepare(string $sql, mixed ...$params): string {return $sql;}
        public function get_var(string $sql): mixed {
            if (str_starts_with($sql,'SHOW TABLES LIKE')) {return $this->schemaAvailable?'wp_smai_metrics':false;}
            return $this->count;
        }
        public function get_results(string $sql, mixed $format = null): mixed{return $this->rows;}
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $s): string {return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');}
}
if (!function_exists('esc_html')) {
    function esc_html(string $s): string {return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');}
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $s, string $domain = ''): string{return esc_html($s);}
}
if (!function_exists('wp_unique_id')) {
    function wp_unique_id(string $prefix = ''): string {
        static $next = 0;
        return $prefix . (++$next);
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $result): bool{return $result instanceof WP_Error;}
}
use Sabri\AnalyticsIntelligence\Admin\AdminPages;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$fake = new wpdb();
$db = new Database($fake);
$ref = new ReflectionClass(AdminPages::class);
$controller = $ref->newInstanceWithoutConstructor();
$ref->getProperty('db')->setValue($controller,$db);
$methods=[];
foreach(['table','countWhere','rows','groupedQuarantine','formatCount']as $name){
 $methods[$name]=$ref->getMethod($name);
}
$passed=0;$failed=0;
function admin_expect(bool $ok,string $label):void {
 global $passed,$failed;
 echo ($ok?'PASS':'FAIL').' V12.3 admin UI '.$label.PHP_EOL;
 $ok?$passed++:$failed++;
}
function render_admin(ReflectionMethod $method, AdminPages $controller, string $title, mixed $rows):string {
 ob_start();
 $method->invoke($controller,$title,$rows);
 return (string)ob_get_clean();
}
$healthCount=$ref->getMethod('healthCount');
admin_expect($healthCount->invoke($controller,['queue'=>['status'=>'unavailable']],'queue','dead_letter')==='Unavailable', 'missing queue evidence never renders 0');
admin_expect($healthCount->invoke($controller,['quality'=>['status'=>'unavailable']],'quality','open_issues')==='Unavailable', 'missing quality evidence never renders 0');
admin_expect($healthCount->invoke($controller,['queue'=>['dead_letter'=>0]],'queue','dead_letter')==='0', 'verified empty dead-letter count remains zero');
admin_expect($healthCount->invoke($controller,['quality'=>['open_issues'=>12]],'quality','open_issues')==='12', 'verified open quality issue count displayed');

$table=$methods['table'];
$out=render_admin($table,$controller,'Governed Metrics',[]);
admin_expect(str_contains($out,'No records were returned')&&!str_contains($out,'Unavailable'),'verified empty result visibly distinct from SQL failure');
admin_expect(str_contains($out,'aria-labelledby')&&str_contains($out,'role="status"'),'verified empty state is accessibly labeled');

$out=render_admin($table,$controller,'Governed Metrics',new WP_Error('sql_unavailable'));
admin_expect(str_contains($out,'Unavailable: table evidence') && str_contains($out,'not a zero count'),'SQL failure not presented as empty');
admin_expect(str_contains($out,'role="alert"'),'unavailable table uses accessible alert state');
$out=render_admin($table,$controller,'Test & Records',[['metric_id'=>'metric.one','name'=>'<b>unsafe</b>','value'=>0]]);
admin_expect(str_contains($out,'<table ')&&str_contains($out,'<thead>')&&str_contains($out,'<tbody>'),'real two-dimensional table renderer implemented');
admin_expect(str_contains($out,'role="region"')&&str_contains($out,'tabindex="0"'),'wide table keyboard-scrollable');
admin_expect(str_contains($out,'&lt;b&gt;unsafe&lt;/b&gt;')&&!str_contains($out,'<b>unsafe</b>'),'database text is escaped, never HTML-injected');
admin_expect(str_contains($out,'<td>0</td>'),'valid zero remains actual zero');
admin_expect(str_contains($out,'Test &amp; Records'),'section heading escaped');
admin_expect(substr_count($out,'scope="col"')===3,'all query columns have proper table header scope');
admin_expect(str_contains($out,'at most 100 current query rows'),'large admin datasets explicitly paginated/bounded');
$out=render_admin($table,$controller,'Records',[['bad'=>['not'=>'scalar']]]);
admin_expect(str_contains($out,'[unavailable]'),'complex row values not expanded into private arrays');

$out=render_admin($table,$controller,'Records',[['x'=>1],['x'=>2]]);
admin_expect(substr_count($out,'<tr>')===3,'one header and two body rows rendered');

$columns=array_fill_keys(range(1,25),'data');
$out=render_admin($table,$controller,'Records',[$columns]);
admin_expect(str_contains($out,'exceed the limit'),'overly wide malformed table rejected');

$fake->count=false;
admin_expect($methods['countWhere']->invoke($controller,'metrics',"state='active'")===null,'failed SQL COUNT remains unknown');
admin_expect($methods['formatCount']->invoke($controller,null)==='Unavailable','missing SQL total shows Unavailable');
$fake->count='0';
admin_expect($methods['countWhere']->invoke($controller,'metrics',"state='active'")===0,'successful zero remains zero');
$fake->count='17';
admin_expect($methods['countWhere']->invoke($controller,'metrics',"state='active'")===17,'verified positive count retained');
foreach(['01','1e309',null,-1,false,'bad','92233720368547758070'] as $invalid){
 $fake->count=$invalid;
 admin_expect($methods['countWhere']->invoke($controller,'metrics',"state='active'")===null,'invalid count evidence denied');
}

$fake->count='5'; $fake->schemaAvailable=false;
admin_expect($methods['countWhere']->invoke($controller,'metrics',"state='active'")===null,'missing schema is unknown, not zero');
$fake->schemaAvailable=true;$fake->rows=false;
admin_expect(is_wp_error($methods['rows']->invoke($controller,'metrics','metric_id,state')),'failed admin result is an explicit WP error');
$fake->rows=[];
admin_expect($methods['rows']->invoke($controller,'metrics','metric_id,state')===[],'verified empty query is empty');
admin_expect(is_wp_error($methods['rows']->invoke($controller,'metrics','id;DROP TABLE users')),'invalid admin column list refused');
admin_expect(is_wp_error($methods['rows']->invoke($controller,'metrics','metric_id',"state='injected'")),'unapproved where clause refused');
$fake->rows=false;
admin_expect(is_wp_error($methods['groupedQuarantine']->invoke($controller)),'quarantine SQL failure explicitly unavailable');
echo 'CF-05 V12.3 admin table and SQL truth: '.$passed.'/'.($passed+$failed).PHP_EOL;
exit($failed===0?0:1);
