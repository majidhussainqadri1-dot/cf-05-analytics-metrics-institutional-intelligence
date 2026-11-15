<?php
declare(strict_types=1);

/** CF05-FR-035 V12.3 restore-proof integrity, synthetic fail-closed fixtures. */
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
        public mixed $countResult = '0';
        public array $rowsByTable = [];
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_var(string $sql): mixed { return $this->countResult; }
        public function get_results(string $sql, mixed $mode = null): mixed {
            foreach ($this->rowsByTable as $table => $rows) {
                if (str_contains($sql, $table)) { return $rows; }
            }
            return [];
        }
        public function query(string $sql): int { return 1; }
    }
}
if (!function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool { return $userId === 5; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $default, mixed ...$args): mixed { return $default; }
}

use Sabri\AnalyticsIntelligence\Domain\RestoreService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$db = new wpdb();
$svc = new RestoreService(new Database($db));
$reflect = new ReflectionClass(RestoreService::class);
$method = [];
foreach (['requiredCount','checkpointSnapshot','catalogHash','verifyDeletionFloor','verifyDeletedSubjects','verifyAccessFloor','verifyProviders'] as $name) {
    $method[$name] = $reflect->getMethod($name);
}
$okCount = 0; $failed = 0;
function evidence_check(bool $ok, string $description): void {
    global $okCount,$failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' V12.3 restore ' . $description . PHP_EOL;
    $ok ? $okCount++ : $failed++;
}
function restore_throws(callable $f): bool {
    try { $f(); } catch (RuntimeException $e) { return true; }
    return false;
}
$required = static fn(mixed $v) => $method['requiredCount']->invoke($svc,$v,'Synthetic count');
foreach (['0' => 0, '7' => 7] as $source => $expected) {
    evidence_check($required($source) === $expected, 'canonical COUNT ' . $source);
}
foreach ([false,null,'invalid','-1','0.5','01','1e309'] as $invalid) {
    evidence_check(restore_throws(static fn() => $required($invalid)), 'failed/count-corrupt SQL aggregate denied');
}
$db->rowsByTable['smai_checkpoints'] = false;
evidence_check(restore_throws(static fn() => $method['checkpointSnapshot']->invoke($svc)), 'failed checkpoint read not recorded as empty');
$db->rowsByTable['smai_checkpoints'] = [];
evidence_check($method['checkpointSnapshot']->invoke($svc) === [], 'successful empty checkpoint list valid');

$db->rowsByTable['smai_metrics'] = false;
evidence_check(restore_throws(static fn() => $method['catalogHash']->invoke($svc)), 'failed metric catalog read cannot make restore hash');
$db->rowsByTable['smai_metrics'] = [];
$catalog = $method['catalogHash']->invoke($svc);
evidence_check(is_string($catalog) && preg_match('/^[a-f0-9]{64}$/D',$catalog) === 1, 'valid empty catalog produces versioned hash');

$db->countResult = false;
evidence_check(restore_throws(static fn() => $method['verifyDeletionFloor']->invoke($svc, 1)), 'failed incomplete-deletion count cannot verify floor');
$db->countResult = '0';
evidence_check($method['verifyDeletionFloor']->invoke($svc, 1) === true, 'zero incomplete deletions verifies');
$db->countResult = '1';
evidence_check($method['verifyDeletionFloor']->invoke($svc, 1) === false, 'unfinished deletion blocks verification');

$db->rowsByTable['smai_deletion_jobs'] = false;
evidence_check(restore_throws(static fn() => $method['verifyDeletedSubjects']->invoke($svc, 1)), 'failed deleted-subject enumeration cannot verify');
$db->rowsByTable['smai_deletion_jobs'] = [['deletion_key' => str_repeat('b',64)]];
$db->countResult = false;
evidence_check(restore_throws(static fn() => $method['verifyDeletedSubjects']->invoke($svc, 1)), 'failed deleted-subject residue count cannot verify');
$db->countResult = '0';
evidence_check($method['verifyDeletedSubjects']->invoke($svc, 1) === true, 'verified absent deleted subjects accepted');

$db->countResult = false;
evidence_check(restore_throws(static fn() => $method['verifyAccessFloor']->invoke($svc, 1)), 'failed access revocation evidence denied');
$db->countResult = '0';
evidence_check($method['verifyAccessFloor']->invoke($svc, 1) === true, 'zero stale access resources accepted');
$db->countResult = '1';
evidence_check($method['verifyAccessFloor']->invoke($svc, 1) === false, 'stale access resources block verification');

$db->rowsByTable['smai_providers'] = false;
evidence_check(restore_throws(static fn() => $method['verifyProviders']->invoke($svc, '11111111-1111-4111-8111-111111111111')), 'failed provider list cannot verify restore');
$db->rowsByTable['smai_providers'] = [];
evidence_check($method['verifyProviders']->invoke($svc, '11111111-1111-4111-8111-111111111111') === true, 'successful empty external provider list is valid');
$db->rowsByTable['smai_providers'] = [['provider_id'=>'external-cloud','provider_version'=>'1.0.0']];
evidence_check($method['verifyProviders']->invoke($svc, '11111111-1111-4111-8111-111111111111') === false, 'external provider without independent restore evidence denied');

$db->countResult='0';
$badTimestamp = $svc->record(str_repeat('a',40),[
    'backup_manifest_hash'=>str_repeat('b',64),
    'backup_archive_hash'=>str_repeat('c',64),
    'backup_created_at'=>"2026-10-21T19:44:55Z\n",
],5);
evidence_check($badTimestamp instanceof WP_Error && $badTimestamp->get_error_code()==='smai_invalid_restore_timestamp','backup timestamp trailing newline denied');

echo 'CF-05 V12.3 restore-proof integrity: ' . $okCount . '/' . ($okCount + $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
