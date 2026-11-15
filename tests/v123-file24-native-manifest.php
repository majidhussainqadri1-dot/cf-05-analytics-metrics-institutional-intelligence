<?php

declare(strict_types=1);

/**
 * CF-05 V12.3: File 24's actual current ModuleRegistry 1.2.0 manifest
 * shape, plus strict prohibition on self-declared production acceptance.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('SMAI_VERSION')) { define('SMAI_VERSION', '1.0.0-rc.18'); }
if (!defined('SMAI_SCHEMA_VERSION')) { define('SMAI_SCHEMA_VERSION', '1.4.1'); }
if (!defined('SMAI_CONTRACT_VERSION')) { define('SMAI_CONTRACT_VERSION', '1.4.0'); }
if (!class_exists('wpdb')) { class wpdb { public string $prefix = 'wp_'; } }

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['v123_file24_hooks'] = [];
if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): bool {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): bool {
        $GLOBALS['v123_file24_hooks'][$hook] = $callback;
        return true;
    }
}

use Sabri\AnalyticsIntelligence\Infrastructure\IntegrationRegistry;
$registry = new IntegrationRegistry();
$registry->register();
$pass=0;$fail=0;
function file24_expect(bool $condition, string $name): void {
    global $pass,$fail;
    echo ($condition ? 'PASS' : 'FAIL') . ' V12.3 File24 module manifest ' . $name . PHP_EOL;
    $condition ? $pass++ : $fail++;
}
file24_expect(isset($GLOBALS['v123_file24_hooks']['spcrc/module_manifests']), 'current owner registry filter installed');
$old=['module_key'=>'file-20-shell','name'=>'Existing independently owned module'];
$result=$registry->appendNativeFile24Manifest([$old]);
file24_expect(count($result)===2 && $result[0]===$old,'does not overwrite prior registered modules');
$manifest=$result[1];
$identity=['module_key','name','version','owner','data_classes','public_routes','private_routes'];
$full=['tables','files','capabilities','external_vendors','secret_classes','privacy_operations','exporters','erasers','emergency_callbacks','last_security_test','verification_level','contract_version','canonical_data_owner','canonical_action_owner','evidence_source','degraded_behavior','release_gate'];
foreach(array_merge($identity,$full) as $field) {
    file24_expect(array_key_exists($field,$manifest),'File24 native required field '.$field.' present');
}
file24_expect($manifest['module_key']==='cf-05' && $manifest['owner']==='CF 05', 'unambiguous canonical identity');
file24_expect($manifest['version']===SMAI_VERSION && $manifest['contract_version']==='1.2.0','source version and exact reviewed File24 1.2.0 contract');
file24_expect($manifest['posture']==='unassessed','no fabricated operational or accepted posture');
file24_expect($manifest['last_security_test']==='' && $manifest['verification_level']==='','no invented independent security test or ASVS verification');
file24_expect($manifest['public_routes']===[],'no anonymous analytics APIs advertised');
file24_expect(is_array($manifest['tables']) && count($manifest['tables'])===45,'all 45 source-owned database table families declared');
file24_expect(in_array('events',$manifest['tables'],true) && in_array('deletion_jobs',$manifest['tables'],true)
    && in_array('restore_points',$manifest['tables'],true),'privacy and restore database owners discoverable');
file24_expect(is_array($manifest['private_routes']) && count($manifest['private_routes'])===4,'bounded real private REST route set');
file24_expect(array_reduce($manifest['private_routes'], static fn(bool $carry,string $route): bool =>
    $carry && str_starts_with($route,'/wp-json/sabri-analytics/v1/') && !str_contains($route,'?') && !str_contains($route,'#'),true),'no external or parameter-smuggled URL');
file24_expect($manifest['privacy_operations']===[],'cannot claim native File24 deletion without verified source-owner deletion key');
file24_expect(in_array('runtime-disable',$manifest['emergency_callbacks'],true),'only code-owned emergency runtime stop declared');
file24_expect(preg_match('/^[a-z][a-z0-9_-]{1,31}:[A-Za-z0-9][A-Za-z0-9._:-]{2,220}$/D',$manifest['evidence_source'])===1,'opaque source reference format accepted by File24');
file24_expect(str_contains($manifest['release_gate'],'production parity pending'),'production acceptance still blocked');
file24_expect($registry->appendNativeFile24Manifest('invalid')[0]['posture']==='unassessed', 'invalid existing collection is not trusted');
echo 'CF-05 V12.3 native File24 registry contract: '.$pass.'/'.($pass+$fail).PHP_EOL;
exit($fail===0?0:1);
