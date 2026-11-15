<?php

declare(strict_types=1);

/**
 * File 23 native source contract integration (2.0.0), synthetic stubs.
 * The upstream interface signatures match File 23 main; independently
 * verify actual staging plugin versions before using these in production.
 */
require __DIR__ . '/bootstrap.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!defined('SMAI_VERSION')) { define('SMAI_VERSION', '1.0.0-rc.19'); }
if (!defined('SMAI_SCHEMA_VERSION')) { define('SMAI_SCHEMA_VERSION', '1.4.1'); }
if (!defined('SMAI_CONTRACT_VERSION')) { define('SMAI_CONTRACT_VERSION', '1.4.0'); }
if (!defined('SMAI_ACTIVATION_EVIDENCE_SHA256')) { define('SMAI_ACTIVATION_EVIDENCE_SHA256', str_repeat('a', 64)); }
foreach (['SMAI_INGESTION_SECRET', 'SMAI_PSEUDONYM_KEY', 'SMAI_EXPORT_KEY'] as $secret) {
    if (!defined($secret)) { define($secret, str_repeat('x', 48)); }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public mixed $projectRows = [];
        public mixed $metricRow = null;
        public function prepare(string $sql, mixed ...$arguments): string { return $sql; }
        public function get_results(string $sql, mixed $mode = null): mixed { return $this->projectRows; }
        public function get_row(string $sql, mixed $mode = null): mixed { return $this->metricRow; }
    }
}
interface SPDB_Provider_Adapter {
    public function get_provider_key(): string;
    public function get_provider_name(): string;
    public function get_provider_version(): string;
    public function get_minimum_contract_version(): string;
    public function get_maximum_contract_version(): string;
    public function get_declared_capability_state(): string;
    public function get_object_types(): array;
    public function get_privacy_classifications(): array;
    public function get_supported_capabilities(): array;
    public function get_operation_definitions(): array;
    public function health_check(): array;
    public function list_items(array $query);
    public function get_item(string $object_type, string $object_id);
    public function get_allowed_operations(string $object_type, string $object_id): array;
    public function execute_operation(string $operation_key, string $object_type, string $object_id, array $payload);
}
interface SPDB_Analytics_Provider { public function get_spdb_aggregate_metrics(array $query); }
final class SPDB_Adapter_Registry {
    public array $adapters = [];
    public function register(SPDB_Provider_Adapter $adapter): bool {
        $this->adapters[$adapter->get_provider_key()] = $adapter;
        return true;
    }
}
final class SPDB_Capabilities {
    public static function current_user_can(string $capability, mixed ...$args): bool {
        return $capability === 'spdb_view_global_analytics'
            && $GLOBALS['v123_file23_allowed'];
    }
}
$GLOBALS['v123_file23_runtime'] = 'foundation_disabled';
$GLOBALS['v123_file23_schema'] = '1.4.1';
$GLOBALS['v123_file23_allowed'] = true;
$GLOBALS['v123_file23_user'] = 7;
$GLOBALS['v123_file23_grants'] = [];
$GLOBALS['v123_file23_hooks'] = [];
if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = null): mixed {
        return match ($key) {
            'smai_runtime_state' => $GLOBALS['v123_file23_runtime'],
            'smai_schema_version' => $GLOBALS['v123_file23_schema'],
            'smai_schema_migration_error' => null,
            'smai_activation_approved' => '1',
            'smai_activation_evidence_hash' => str_repeat('a', 64),
            default => $default
        };
    }
}
if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string { return 'staging'; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return $GLOBALS['v123_file23_user']; }
}
if (!function_exists('user_can')) {
    function user_can(int $user, string $capability): bool {
        return $user === 7 && $capability === 'smai_query_metrics';
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $default, mixed ...$args): mixed {
        return $hook === 'smai_file23_approved_metric_grants' ? $GLOBALS['v123_file23_grants'] : $default;
    }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $arity = 1): bool {
        $GLOBALS['v123_file23_hooks'][$hook] = [$callback, $priority, $arity];
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $arity = 1): bool {
        return true;
    }
}

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\IntegrationRegistry;
use Sabri\AnalyticsIntelligence\Integration\File23AnalyticsAdapter;

$GLOBALS['wpdb'] = new wpdb();
$adapter = new File23AnalyticsAdapter(new Database($GLOBALS['wpdb']));
$pass = 0; $fail = 0;
function bridge_check(bool $ok, string $name): void {
    global $pass, $fail;
    echo ($ok ? 'PASS' : 'FAIL') . ' File23 native bridge ' . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}
function bridge_error(mixed $response, string $errorCode): bool {
    return $response instanceof WP_Error && $response->get_error_code() === $errorCode;
}
bridge_check($adapter instanceof SPDB_Provider_Adapter && $adapter instanceof SPDB_Analytics_Provider, 'both actual native interface families implemented');
bridge_check($adapter->get_provider_key() === 'cf05_analytics', 'immutable canonical provider identity');
bridge_check($adapter->get_provider_version() === SMAI_VERSION, 'runtime semantic version source-bound');
bridge_check($adapter->get_minimum_contract_version() === '2.0.0'
    && $adapter->get_maximum_contract_version() === '2.0.0', 'exact File23 adapter contract bounded');
bridge_check($adapter->get_declared_capability_state() === 'unavailable', 'disabled CF05 never appears operational');
bridge_check($adapter->health_check()['live_verified'] === false, 'no fabricated live claim');
bridge_check($adapter->get_operation_definitions() === [] && $adapter->get_allowed_operations('analytics_metric','1') === [], 'no native mutation rights');
bridge_check(bridge_error($adapter->execute_operation('delete','analytics_metric','1',[]),'smai_file23_readonly'), 'native operation denied');
bridge_check(bridge_error($adapter->get_item('analytics_metric','1'),'smai_file23_readonly'), 'raw native item denied');
bridge_check(bridge_error($adapter->list_items([]),'smai_file23_readonly'), 'raw native list denied');

$registry = new IntegrationRegistry();
$registry->register();
bridge_check(isset($GLOBALS['v123_file23_hooks']['spdb/register_adapters']), 'actual File23 registration hook attached');
$native = new SPDB_Adapter_Registry();
$registry->registerNativeFile23Adapter($native);
bridge_check(isset($native->adapters['cf05_analytics']) && $native->adapters['cf05_analytics'] instanceof File23AnalyticsAdapter, 'native File23 registry receives correct adapter');

$ctx=['viewer_user_id'=>7,'scope'=>'institution','date_from'=>'2026-11-01','date_to'=>'2026-11-02'];
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_access_denied'), 'disabled analytics cannot query');
$GLOBALS['v123_file23_runtime']='staging_active';
bridge_check($adapter->get_declared_capability_state() === 'read_only', 'staging gate is read only');
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics(array_replace($ctx,['viewer_user_id'=>8])),'smai_file23_viewer_mismatch'), 'other subject cannot use viewer context');
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics(array_replace($ctx,['scope'=>'own'])),'smai_file23_own_scope_unavailable'), 'unproven object-owned analytics refused');
$GLOBALS['v123_file23_allowed']=false;
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_access_denied'), 'native File23 global capability required');
$GLOBALS['v123_file23_allowed']=true;
foreach ([['date_from'=>"2026-11-01\n"], ['date_from'=>'2026-02-29'],['date_to'=>'2026-02-31'],['date_from'=>'2026-11-05'],['date_to'=>'today']] as $bad) {
    bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics(array_replace($ctx,$bad)), 'smai_file23_window_required'),
        'malformed or reversed day window denied');
}
bridge_check($adapter->get_spdb_aggregate_metrics($ctx) === ['metrics'=>[], 'generated_at'=>$adapter->get_spdb_aggregate_metrics($ctx)['generated_at']], 'no grant cannot invent value');
$GLOBALS['v123_file23_grants']=['not an approved grant'];
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_grant_invalid'),'malformed grant fails before metric query');
$GLOBALS['v123_file23_grants']=[['metric_id'=>'metric.done','metric_version'=>'1.0.0','project_uuid'=>'11111111-1111-4111-8111-111111111111','purpose'=>'institutional reporting','source_owner'=>'file-05','unit'=>'count']];
$GLOBALS['v123_file23_grants'][]=$GLOBALS['v123_file23_grants'][0];
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_duplicate_grant'),'duplicate native metric grants rejected before queries');
$GLOBALS['v123_file23_grants']=[ $GLOBALS['v123_file23_grants'][0] ];
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_unapproved_grant'),'external filter cannot enlarge missing native metric grant');
$GLOBALS['v123_file23_grants']=array_fill(0,21,['invalid']);
bridge_check(bridge_error($adapter->get_spdb_aggregate_metrics($ctx),'smai_file23_grants_invalid'),'more than 20 metric grants fail closed');
$GLOBALS['v123_file23_grants']=[];

$reflection=new ReflectionClass(File23AnalyticsAdapter::class);
$grantMethod=$reflection->getMethod('approvedGrant');
$validGrant=['metric_id'=>'metric.done','metric_version'=>'1.0.0','project_uuid'=>'11111111-1111-4111-8111-111111111111','purpose'=>'institutional reporting','source_owner'=>'file-05','unit'=>'count'];
bridge_check($grantMethod->invoke(null,$validGrant)===$validGrant,'canonical exact grant accepted');
foreach ([['metric_id'=>'metric.done'."\n"],['source_owner'=>'file-05'."\n"],['project_uuid'=>'123'],['purpose'=>'unreviewed'],['unit'=>'count;drop'],['extra'=>'injected'],['metric_version'=>'01'],['metric_id'=>19]] as $bad) {
    bridge_check($grantMethod->invoke(null,array_replace($validGrant,$bad))===null,'noncanonical or additional grant field rejected');
}
$present=$reflection->getMethod('presentApprovedAggregate');
$from=new DateTimeImmutable('2026-11-01T00:00:00+00:00');
$to=new DateTimeImmutable('2026-11-03T00:00:00+00:00');
$sample=[
 'metric_id'=>'metric.done','metric_version'=>'1.0.0','source_owner'=>'file-05',
 'quality_status'=>'green','status'=>'current_within_declared_quality',
 'window'=>['start'=>$from->format('c'),'end'=>$to->format('c')],
 'value'=>24.0,'cohort_size'=>100,'minimum_cohort'=>20,
 'name'=>'Lessons completed','business_question'=>'Approved successful learning completions',
 'data_through'=>gmdate('c',time()-3600),
];
$goodMetric=$present->invoke(null,$sample,$validGrant,$from,$to);
bridge_check(is_array($goodMetric)&&$goodMetric['value']===24.0&&$goodMetric['cohort_count']===100,'eligible aggregate converted without raw identity');
foreach ([['quality_status'=>'warning'],['quality_status'=>'unknown'],['quality_status'=>'suppressed'],['cohort_size'=>19],['source_owner'=>'file-04'],['value'=>INF],['value'=>null],['minimum_cohort'=>0],['status'=>'unknown_or_degraded'],['data_through'=>null],['data_through'=>gmdate('c',time()+7200)],['window'=>['start'=>'2026-11-01T00:00:01+00:00','end'=>$to->format('c')]]] as $bad) {
    bridge_check($present->invoke(null,array_replace($sample,$bad),$validGrant,$from,$to)===null, 'non-current, out-of-scope or private metric not displayed');
}
$nativeGrantResolver = $reflection->getMethod('nativeApprovedGrants');
$db = $GLOBALS['wpdb'];
$db->projectRows = [];
bridge_check($nativeGrantResolver->invoke($adapter,7) === [], 'no active native project returns zero grants without fabricated data');
$db->projectRows = false;
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,7),'smai_file23_project_inventory_unavailable'), 'failed native project SQL read is not empty');
$db->projectRows = array_fill(0,101,['project_uuid'=>'bad']);
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,7),'smai_file23_project_inventory_unavailable'), 'more than 100 active projects fail bounded read');
$db->projectRows = [['project_uuid'=>'11111111-1111-4111-8111-111111111111','purpose'=>'institutional reporting','datasets_json'=>'not-json']];
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,7),'smai_file23_project_refs_corrupt'), 'invalid source project JSON rejected');
$db->projectRows[0]['datasets_json'] = '["metric:metric.done@1.0.0"]';
$db->metricRow = [
    'metric_id'=>'metric.done','metric_version'=>'1.0.0','owner_module'=>'file-05',
    'definition_json'=>'{"unit":"count"}','state'=>'active'
];
$nativeGrants = $nativeGrantResolver->invoke($adapter,7);
bridge_check(is_array($nativeGrants) && count($nativeGrants)===1
    && $nativeGrants[0]['project_uuid']==='11111111-1111-4111-8111-111111111111'
    && $nativeGrants[0]['unit']==='count', 'native active access project discovers canonical metric without external filter');
$db->projectRows[] = ['project_uuid'=>'22222222-2222-4222-8222-222222222222','purpose'=>'institutional reporting','datasets_json'=>'["metric:metric.done@1.0.0"]'];
$nativeGrants = $nativeGrantResolver->invoke($adapter,7);
bridge_check(is_array($nativeGrants) && count($nativeGrants)===1,'same metric in two approved projects is deduplicated by canonical id');
$db->metricRow['definition_json']='{"unit":"percent;unsafe"}';
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,7),'smai_file23_metric_contract_corrupt'), 'metric unit cannot be silently changed to unsupported type');
$db->metricRow=null;
$db->last_error='synthetic database outage';
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,7),'smai_file23_metric_catalog_unavailable'), 'failed metric catalog SQL read cannot masquerade as inactive metric');
$db->last_error='';
$db->projectRows=[];
$db->metricRow=null;
bridge_check(bridge_error($nativeGrantResolver->invoke($adapter,0),'smai_file23_invalid_viewer'),'missing owner identity fails closed');

echo 'CF-05 V12.3 File23 native integration: '.$pass.'/'.($pass+$fail).PHP_EOL;
exit($fail===0?0:1);
