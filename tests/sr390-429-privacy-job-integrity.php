<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('SMAI_PSEUDONYM_KEY')) define('SMAI_PSEUDONYM_KEY', str_repeat('s', 48));
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error(mixed $x): bool { return $x instanceof WP_Error; } }
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix='wp_';
        public int $insertResult=1;
        public mixed $existing=null;
        public bool $inserted=false;
        public function prepare(string $sql,mixed ...$args): string { return $sql; }
        public function get_var(string $sql): mixed { return 1; }
        public function get_results(string $sql,mixed $format=null): array { return []; }
        public function query(string $sql): int|false {
            if (str_contains($sql,'INSERT IGNORE')) { $this->inserted=true; return $this->insertResult; }
            return 1;
        }
        public function get_row(string $sql,mixed $format=null): mixed { return $this->existing; }
    }
}
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Domain\QueryPrivacyGuard;
$failed=0;
function check390(bool $ok,string $label): void {
    global $failed;
    echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;
    if (!$ok) ++$failed;
}
function checkWindow(wpdb $db,string $start,string $end): bool|WP_Error {
    return (new QueryPrivacyGuard(new Database($db)))->evaluateAndRecord(
        'education.completion','1.0.0','11111111-1111-4111-8111-111111111111',5,
        'approved institutional aggregate reporting',$start,$end,[],
        ['minimum_cohort'=>20],60,20
    );
}
$db=new wpdb();$r=checkWindow($db,'tomorrow','next friday');
check390($r instanceof WP_Error && $r->get_error_code()==='smai_privacy_window_invalid' && !$db->inserted,
    'Relative windows cannot authorize a privacy disclosure');
$db=new wpdb();$r=checkWindow($db,'2026-10-02 00:00:00','2026-10-01 00:00:00');
check390($r instanceof WP_Error && $r->get_error_code()==='smai_privacy_window_invalid' && !$db->inserted,
    'Reversed windows cannot authorize a privacy disclosure');
$db=new wpdb();$r=checkWindow($db,'2026-02-30 00:00:00','2026-03-02 00:00:00');
check390($r instanceof WP_Error && !$db->inserted,'Impossible calendar dates are rejected');
$db=new wpdb();$r=checkWindow($db,"2026-10-01 00:00:00\0",'2026-10-02 00:00:00');
check390($r instanceof WP_Error && !$db->inserted,'NUL-injected windows are rejected without exception');
$db=new wpdb();$r=checkWindow($db,'2026-10-01 00:00:00','2026-10-02 00:00:00');
check390($r===true && $db->inserted,'Well-formed UTC privacy window remains executable');
$db=new wpdb();$db->insertResult=0;$db->existing=null;
$r=(new JobQueue(new Database($db)))->enqueue('quality.run',['sample'=>1],'duplicate-case-abcdef');
check390($r instanceof WP_Error && $r->get_error_code()==='smai_job_idempotency_conflict',
    'Unreadable duplicate job cannot report success');
$db=new wpdb();$db->insertResult=0;$db->existing=['job_uuid'=>'','state'=>'unknown'];
$r=(new JobQueue(new Database($db)))->enqueue('quality.run',['sample'=>1],'duplicate-case-abcdef');
check390($r instanceof WP_Error && $r->get_error_code()==='smai_job_idempotency_conflict',
    'Malformed duplicate ID/state cannot report success');
$db=new wpdb();$db->insertResult=0;
$db->existing=['job_uuid'=>'11111111-1111-4111-8111-111111111111','state'=>'queued','job_type'=>'quality.run','payload_json'=>'{"sample":1}'];
$r=(new JobQueue(new Database($db)))->enqueue('quality.run',['sample'=>1],'duplicate-case-abcdef');
check390(is_array($r) && ($r['duplicate']??false) && ($r['job_uuid']??'')==='11111111-1111-4111-8111-111111111111',
    'Genuine duplicate job is returned unchanged');
echo 'CF-05 SR390-429 independent regression assertions: '.(8-$failed).'/8'.PHP_EOL;
exit($failed===0?0:1);
