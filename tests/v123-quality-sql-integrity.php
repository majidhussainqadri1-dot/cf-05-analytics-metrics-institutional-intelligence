<?php
declare(strict_types=1);

/**
 * CF05-FR-011 V12.3 data-quality evidence: a failed warehouse read is not
 * an empty, fully passing data sample or a missing reference relation.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool { return $userId === 5; }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public mixed $ruleRows = [];
        public mixed $dataRows = [];
        public mixed $referenceRows = [];
        public mixed $referenceBuild = '11111111-1111-4111-8111-111111111111';
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_row(string $sql, mixed $format = null): array {
            return str_contains($sql,'smai_dataset_builds')
                ? ['build_uuid'=>'11111111-1111-4111-8111-111111111111','is_active'=>1]
                : ['id'=>1];
        }
        public function get_results(string $sql, mixed $format = null): mixed {
            if (str_contains($sql,'smai_quality_rules')) { return $this->ruleRows; }
            if (str_contains($sql,'smai_dataset_rows')) { return $this->dataRows; }
            return [];
        }
        public function get_var(string $sql): mixed { return $this->referenceBuild; }
        public function get_col(string $sql): mixed { return $this->referenceRows; }
    }
}

use Sabri\AnalyticsIntelligence\Domain\QualityService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$wpdb = new wpdb();
$quality = new QualityService(new Database($wpdb));
$ref = new ReflectionClass(QualityService::class);
$evaluate = $ref->getMethod('evaluate');
$passes=0;$failed=0;
function confirm_quality(bool $condition, string $message): void {
    global $passes,$failed;
    echo ($condition?'PASS':'FAIL') . ' V12.3 quality ' . $message . PHP_EOL;
    if($condition){$passes++;}else{$failed++;}
}
function quality_fails_closed(callable $work): bool {
    try{$work();}catch(RuntimeException $ex){return true;}
    return false;
}
$run = static fn() => $quality->runJob(['dataset_ref'=>'education.events@1.0.0','actor_user_id'=>5]);
$wpdb->ruleRows=false;$wpdb->dataRows=[];
confirm_quality(quality_fails_closed($run),'failed rule enumeration must not create a quality status');
$wpdb->ruleRows=[];$wpdb->dataRows=false;
confirm_quality(quality_fails_closed($run),'failed data-row enumeration must not create a green result');

$rule=['field'=>'student_id','target_dataset_ref'=>'education.students@1.0.0','target_field'=>'student_id','max_missing'=>0];
$testReference=static fn(array $source) => $evaluate->invoke($quality,'referential_integrity',$rule,$source,$source,'education.events@1.0.0','11111111-1111-4111-8111-111111111111');
$wpdb->referenceRows=false;
confirm_quality(quality_fails_closed(static fn() => $testReference([])),'unavailable reference table cannot make empty sample pass');
$wpdb->referenceRows=[];
$result=$testReference([['student_id'=>'known-student']]);
confirm_quality($result[0]==='fail' && (int)$result[1]===1,'successful empty reference set marks real missing value');
$wpdb->referenceRows=['known-student'];
$result=$testReference([['student_id'=>'known-student']]);
confirm_quality($result[0]==='pass' && (int)$result[1]===0,'verified reference relation passes');

echo 'CF-05 V12.3 quality-read integrity: '.$passes.'/'.($passes+$failed).PHP_EOL;
exit($failed===0?0:1);
