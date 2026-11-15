<?php
declare(strict_types=1);

/** CF05-FR-034 V12.3: unavailable provider-exit counts never mean "purged". */
require __DIR__ . '/bootstrap.php';

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public array $countResults = ['smai_datasets' => '0','smai_jobs' => '0','smai_deletion_reconciliations' => '0'];
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function esc_like(string $string): string { return $string; }
        public function get_var(string $sql): mixed {
            foreach ($this->countResults as $table => $value) {
                if (str_contains($sql, $table)) { return $value; }
            }
            return false;
        }
    }
}
$GLOBALS['v123_revocation_evidence'] = ['evidence_hash' => str_repeat('a',64)];
if (!function_exists('apply_filters')) {
    function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
        return $name === 'smai_provider_credential_revocation_evidence'
            ? $GLOBALS['v123_revocation_evidence'] : $value;
    }
}
use Sabri\AnalyticsIntelligence\Domain\ProviderService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$db = new wpdb();
$svc = new ProviderService(new Database($db));
$failed = 0; $checks = 0;
function check_exit(bool $ok, string $label): void {
    global $failed,$checks;
    $checks++;
    echo ($ok ? 'PASS' : 'FAIL') . ' V12.3 provider exit ' . $label . PHP_EOL;
    if (!$ok) { $failed++; }
}
$baseline = $db->countResults;
$result = $svc->exitStatus('cloud-provider');
check_exit($result['ready_to_retire'] === true && $result['counts_verified'] === true, 'three proven zero counts plus revocation evidence allow retirement');

foreach (array_keys($baseline) as $field) {
    foreach ([false,null,'invalid','-1','0.5','1e309'] as $bad) {
        $db->countResults = $baseline;
        $db->countResults[$field] = $bad;
        $status = $svc->exitStatus('cloud-provider');
        check_exit($status['ready_to_retire'] === false && $status['counts_verified'] === false
            && in_array(null, [$status['remaining_datasets'],$status['active_jobs'],$status['pending_deletion_reconciliations']], true),
            $field . ' invalid ' . var_export($bad,true) . ' denies retirement');
    }
}
foreach (array_keys($baseline) as $field) {
    $db->countResults = $baseline; $db->countResults[$field]='1';
    $status = $svc->exitStatus('cloud-provider');
    check_exit($status['ready_to_retire'] === false && $status['counts_verified'] === true, $field . ' legitimate outstanding resource denies retirement');
}
$db->countResults = $baseline;
$GLOBALS['v123_revocation_evidence'] = null;
$status = $svc->exitStatus('cloud-provider');
check_exit($status['ready_to_retire'] === false && $status['counts_verified'] === true
    && $status['credential_revocation_evidence'] === null, 'credential revocation evidence still mandatory');
echo 'CF-05 V12.3 provider retirement: ' . ($checks - $failed) . '/' . $checks . PHP_EOL;
exit($failed === 0 ? 0 : 1);
