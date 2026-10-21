<?php
declare(strict_types=1);
namespace Sabri\AnalyticsIntelligence\Domain {
    final class MetricCatalog {
        public function __construct($db) {}
        public function active(string $id,string $version):?array {
            return ['definition'=>['minimum_cohort'=>20,'dimensions'=>[],'dimension_policies'=>[]],
                'minimum_cohort'=>20,'name'=>'Approved Test Metric'];
        }
    }
}
namespace Sabri\AnalyticsIntelligence\Infrastructure {
    final class AuditLogger {
        public function __construct(Database $db) {}
        public function log(string $action,string $type,?string $ref,string $result,array $context=[],?string $purpose=null,?string $traceId=null,?int $actor=null,string $actorType='user'):bool{return true;}
        public function logInOpenTransaction(string $action,string $type,?string $ref,string $result,array $context=[],?string $purpose=null,?string $traceId=null,?int $actor=null,string $actorType='user'):bool{return true;}
    }
}
namespace {
    if (!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
    if (!defined('SMAI_PSEUDONYM_KEY'))define('SMAI_PSEUDONYM_KEY',str_repeat('x',48));
    if (!function_exists('get_option')){function get_option(string $k,mixed $default=null):mixed{return $default;}}
    if (!function_exists('is_wp_error')){function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}}
    if (!function_exists('wp_strip_all_tags')){function wp_strip_all_tags(string $s):string{return strip_tags($s);}}
    if (!function_exists('wp_json_encode')){function wp_json_encode(mixed $x,int $flags=0):string|false{return json_encode($x,$flags);}}
    if (!function_exists('user_can')){function user_can(int $id,string $cap):bool{return in_array($id,[5,7],true)&&in_array($cap,['smai_view_insights','smai_audit'],true);}}
    if (!class_exists('WP_Error')){class WP_Error{public function __construct(public string $code,public string $message='',public array $data=[]){}public function get_error_code():string{return $this->code;}}}
    if (!class_exists('wpdb')) {
        class wpdb {
            public string $prefix='wp_';
            public bool $grant=false;
            public int $transactions=0;
            public function prepare(string $query,mixed ...$args):string{
                return $query.' /* '.implode('|',array_map('strval',$args)).' */';
            }
            public function get_row(string $sql,mixed $format=null):?array {
                if(str_contains($sql,'smai_dashboard_definitions'))return ['dashboard_id'=>'valid.dashboard','dashboard_version'=>'1.0.0','state'=>'active','name'=>'Internal','owner_user_id'=>5,'project_uuid'=>'11111111-1111-4111-8111-111111111111','audience_json'=>'{"user_ids":[5,7]}','expires_at'=>null];
                if(str_contains($sql,'smai_report_deliveries'))return ['id'=>1,'delivery_uuid'=>'11111111-1111-4111-8111-111111111111','report_uuid'=>'22222222-2222-4222-8222-222222222222','state'=>'ready','recipient_hash'=>hash_hmac('sha256','user:7',SMAI_PSEUDONYM_KEY),'token_hash'=>hash('sha256','test_token'),'expires_at'=>'2030-01-01 00:00:00','revoked_at'=>null,'sent_at'=>null,'bundle_json'=>'{"metrics":[{"value":42}]}'];
                if(str_contains($sql,'smai_reports'))return ['project_uuid'=>'11111111-1111-4111-8111-111111111111','state'=>'active','expires_at'=>null,'definition_json'=>'{"metrics":[{"metric_id":"education.rates","metric_version":"1.0.0","dimensions":[]}]}'];
                if(str_contains($sql,'smai_access_projects')&&str_contains($sql,'33333333-3333-4333-8333-333333333333'))return ['project_uuid'=>'33333333-3333-4333-8333-333333333333','owner_user_id'=>7,'state'=>'active','purpose'=>'approved institutional reporting','expires_at'=>'2030-01-01 00:00:00','datasets_json'=>'["metric:education.rates@1.0.0"]','fields_json'=>'{}'];
                if(str_contains($sql,'smai_access_projects'))return ['project_uuid'=>'11111111-1111-4111-8111-111111111111','owner_user_id'=>5,'state'=>'active','purpose'=>'approved institutional reporting','expires_at'=>'2030-01-01 00:00:00','datasets_json'=>'["metric:education.rates@1.0.0"]','fields_json'=>'{}'];
                return null;
            }
            public function get_results(string $sql,mixed $format=null):array {
                if(str_contains($sql,'smai_dashboard_widgets'))return [['widget_key'=>'primary','metric_id'=>'education.rates','metric_version'=>'1.0.0','config_json'=>'{"dimensions":[]}','position_order'=>0]];
                if(str_contains($sql,'smai_access_projects')&&str_contains($sql,'owner_user_id='))return $this->grant?[['project_uuid'=>'33333333-3333-4333-8333-333333333333']]:[];
                return [];
            }
            public function query(string $sql):int|false{$this->transactions++;return 1;}
            public function update(string $table,array $data,array $where):int{return 1;}
        }
    }
    spl_autoload_register(static function(string $class):void{
        $prefix='Sabri\\AnalyticsIntelligence\\';
        if(!str_starts_with($class,$prefix))return;
        $file=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require_once $file;
    });
    $sql=new wpdb();
    $db=new \Sabri\AnalyticsIntelligence\Infrastructure\Database($sql);
    $dashboard=new \Sabri\AnalyticsIntelligence\Domain\DashboardService($db);
    $reports=new \Sabri\AnalyticsIntelligence\Domain\ReportService($db);
    $scope=new \Sabri\AnalyticsIntelligence\Domain\AccessProjectService($db);
    $dashboardDenied=$dashboard->bundle('valid.dashboard','1.0.0',7);
    $reportDenied=$reports->accessDelivery('11111111-1111-4111-8111-111111111111','test_token',7);
    $checks=[
        'Unscoped dashboard audience cannot read owner metrics'=>$dashboardDenied instanceof WP_Error && $dashboardDenied->get_error_code()==='smai_dashboard_project_access_denied',
        'Unscoped report delivery token cannot read metrics'=>$reportDenied instanceof WP_Error && $reportDenied->get_error_code()==='smai_report_delivery_scope_denied',
        'No independent recipient grant is denied'=>!$scope->authorizesViewer('11111111-1111-4111-8111-111111111111',7,'metric:education.rates@1.0.0'),
    ];
    $sql->grant=true;
    $checks['Independently approved exact-purpose metric grant is allowed']=$scope->authorizesViewer('11111111-1111-4111-8111-111111111111',7,'metric:education.rates@1.0.0');
    $dashboardAllowed=$dashboard->bundle('valid.dashboard','1.0.0',7);
    $reportAllowed=$reports->accessDelivery('11111111-1111-4111-8111-111111111111','test_token',7);
    $checks['Approved independent viewer retains dashboard access']=is_array($dashboardAllowed);
    $checks['Approved independent viewer retains report delivery access']=is_array($reportAllowed)&&($reportAllowed['metrics'][0]['value']??null)===42;
    $failed=0;
    foreach($checks as $label=>$ok){
        echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;
        if(!$ok)$failed++;
    }
    echo 'SR350-389 delegated viewer regression: '.(count($checks)-$failed).'/'.count($checks).PHP_EOL;
    exit($failed===0?0:1);
}