<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public array $stored = [
            'job_uuid'=>'11111111-1111-4111-8111-111111111111',
            'state'=>'queued',
            'job_type'=>'quality.run',
            'payload_json'=>'{"sample":1}',
            'max_attempts'=>'5',
        ];
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function query(string $sql): int { return 0; }
        public function get_row(string $sql, mixed $format = null): array { return $this->stored; }
    }
}
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
$failed=0;$count=0;
function check_job_retry(bool $ok,string $label): void {
    global $failed,$count; $count++;
    echo ($ok?'PASS':'FAIL')." SR470-509: ".$label.PHP_EOL;
    if (!$ok) $failed++;
}
$db = new wpdb(); $jobs = new JobQueue(new Database($db));
$submit = static fn(int $maxAttempts) => $jobs->enqueue('quality.run',['sample'=>1],'same-job-retry-policy-2026',$maxAttempts);
$good=$submit(5);
check_job_retry(is_array($good) && ($good['duplicate']??false)===true, 'Identical retry policy remains safely idempotent');
$bad=$submit(2);
check_job_retry($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict', 'Different max-attempt budget is a conflicting operation');
$db->stored['max_attempts']='1';
$good=$submit(0);
check_job_retry(is_array($good) && ($good['duplicate']??false)===true, 'Both stored and supplied attempts normalize to minimum one');
$db->stored['max_attempts']='20';
$good=$submit(100);
check_job_retry(is_array($good) && ($good['duplicate']??false)===true, 'Both stored and supplied attempts normalize to maximum twenty');
$db->stored['max_attempts']='0';
$bad=$submit(1);
check_job_retry($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict', 'Corrupted zero stored retry limit fails closed');
$db->stored['max_attempts']='5junk';
$bad=$submit(5);
check_job_retry($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict', 'Noncanonical stored retry limit fails closed');
unset($db->stored['max_attempts']);
$bad=$submit(5);
check_job_retry($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict', 'Absent stored retry limit fails closed');
$db->stored['max_attempts']='5'; $db->stored['payload_json']='{"sample":2}';
$bad=$submit(5);
check_job_retry($bad instanceof WP_Error && $bad->get_error_code()==='smai_job_idempotency_payload_conflict', 'Prior payload immutability guard remains enforced');
echo "SR470-509 retry-policy adversarial fixtures: ".($count-$failed)."/".$count.PHP_EOL;
exit($failed===0?0:1);
