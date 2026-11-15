<?php
declare(strict_types=1);

/**
 * CF05-FR-019/-021 source regression: delegated viewers must be charged
 * against their own independently approved project, not dashboard owner's.
 * Isolated synthetic WPDB; no real SQL or live data.
 */
namespace Sabri\AnalyticsIntelligence\Domain {
    final class MetricCatalog {
        public function __construct($db) {}
        public function active(string $id, string $version): ?array {
            return ['definition'=>[
                'minimum_cohort'=>20,'dimensions'=>[],
                'dimension_policies'=>[],'freshness_seconds'=>86400,
            ],'minimum_cohort'=>20,'name'=>'Approved aggregate'];
        }
    }
}
namespace Sabri\AnalyticsIntelligence\Infrastructure {
    final class AuditLogger {
        public function __construct(Database $db) {}
        public function log(string $action,string $type,?string $ref,string $result,
            array $context=[],?string $purpose=null,?string $traceId=null,
            ?int $actor=null,string $actorType='user'):bool {return true;}
        public function logInOpenTransaction(string $action,string $type,?string $ref,string $result,
            array $context=[],?string $purpose=null,?string $traceId=null,
            ?int $actor=null,string $actorType='user'):bool {return true;}
    }
}
namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
    if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS',86400);
    if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS',3600);
    if (!defined('SMAI_PSEUDONYM_KEY')) define('SMAI_PSEUDONYM_KEY',str_repeat('w',48));
    if (!function_exists('get_option')) {
        function get_option(string $key,mixed $default=null):mixed{return $default;}
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode(mixed $value,int $flags=0):string|false {return json_encode($value,$flags);}
    }
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $value):string{return strip_tags($value);}
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $value):bool{return $value instanceof WP_Error;}
    }
    if (!function_exists('user_can')) {
        function user_can(int $id,string $cap):bool {
            return in_array($id,[5,7],true)&&$cap==='smai_view_insights';
        }
    }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public function __construct(public string $code,public string $message='',public array $data=[]) {}
            public function get_error_code():string{return $this->code;}
        }
    }
    if (!class_exists('wpdb')) {
        class wpdb {
            public string $prefix='wp_';
            public string $last_error='';
            public bool $independentGrant=false;
            public bool $snapshotAvailable=true;
            public bool $privacyLedgerBroken=false;
            public array $privacyWrites=[];
            public array $privacyReads=[];
            public function prepare(string $sql,mixed ...$args):string{
                return $sql.' /* '.implode('|',array_map('strval',$args)).' */';
            }
            public function get_row(string $sql,mixed $format=null):?array {
                if (str_contains($sql,'smai_dashboard_definitions')) return [
                    'dashboard_id'=>'institution.traffic','dashboard_version'=>'1.0.0','state'=>'active',
                    'name'=>'Institutional Traffic','owner_user_id'=>5,
                    'project_uuid'=>'11111111-1111-4111-8111-111111111111',
                    'audience_json'=>'{"user_ids":[5,7]}','expires_at'=>null,
                ];
                if (str_contains($sql,'smai_metric_snapshots')) {
                    if (!$this->snapshotAvailable) return null;
                    return [
                        'metric_id'=>'education.rates','metric_version'=>'1.0.0',
                        'window_start'=>gmdate('Y-m-d H:i:s',time()-7200),
                        'window_end'=>gmdate('Y-m-d H:i:s',time()-3600),
                        'cohort_size'=>'65','quality_status'=>'green',
                        'data_through'=>gmdate('Y-m-d H:i:s',time()-1800),
                        'value_decimal'=>'42.5','uncertainty_json'=>'{}',
                        'caveats_json'=>'[]',
                    ];
                }
                if (str_contains($sql,'smai_access_projects')) {
                    $recipient=str_contains($sql,'33333333-3333-4333-8333-333333333333');
                    return [
                        'project_uuid'=>$recipient?'33333333-3333-4333-8333-333333333333':
                            '11111111-1111-4111-8111-111111111111',
                        'owner_user_id'=>$recipient?7:5,
                        'state'=>'active',
                        'purpose'=>'approved institutional reporting',
                        'expires_at'=>gmdate('Y-m-d H:i:s',time()+86400),
                        'datasets_json'=>'["metric:education.rates@1.0.0"]',
                        'fields_json'=>'{}',
                    ];
                }
                return null;
            }
            public function get_results(string $sql,mixed $format=null):mixed {
                if (str_contains($sql,'smai_dashboard_widgets')) return [[
                    'widget_key'=>'primary','metric_id'=>'education.rates',
                    'metric_version'=>'1.0.0','config_json'=>'{"dimensions":[]}', 'position_order'=>0,
                ]];
                if (str_contains($sql,'smai_access_projects')) {
                    return $this->independentGrant
                        ? [['project_uuid'=>'33333333-3333-4333-8333-333333333333']]
                        : [];
                }
                if (str_contains($sql,"scope='privacy-query'")) {
                    $this->privacyReads[]=$sql;
                    return $this->privacyLedgerBroken?false:[];
                }
                return [];
            }
            public function get_var(string $sql):mixed {
                if (str_contains($sql,'GET_LOCK(')) return 1;
                return 1;
            }
            public function query(string $sql):int|false {
                if (str_contains($sql,'INSERT IGNORE INTO') && str_contains($sql,"'privacy-query'")) {
                    $this->privacyWrites[]=$sql;
                }
                return 1;
            }
        }
    }
    spl_autoload_register(static function(string $class):void{
        $prefix='Sabri\\AnalyticsIntelligence\\';
        if (!str_starts_with($class,$prefix)) return;
        $file=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($file)) require_once $file;
    });

    $wpdb=new wpdb();
    $db=new \Sabri\AnalyticsIntelligence\Infrastructure\Database($wpdb);
    $scope=new \Sabri\AnalyticsIntelligence\Domain\AccessProjectService($db);
    $dash=new \Sabri\AnalyticsIntelligence\Domain\DashboardService($db);
    $source='11111111-1111-4111-8111-111111111111';
    $viewer='33333333-3333-4333-8333-333333333333';
    $metric='metric:education.rates@1.0.0';
    $checks=[];
    $checks['Source owner owns its own privacy project']=
        $scope->authorizedViewerProject($source,5,$metric)===$source;
    $checks['Delegated viewer denied before independent approval']=
        $scope->authorizedViewerProject($source,7,$metric)===null;
    $denied=$dash->bundle('institution.traffic','1.0.0',7);
    $checks['Audience alone never grants data']=
        $denied instanceof WP_Error&&$denied->get_error_code()==='smai_dashboard_project_access_denied';
    $checks['No privacy account created by denied viewer']=$wpdb->privacyWrites===[];
    $wpdb->independentGrant=true;
    $checks['Delegated viewer gets its own project UUID']=
        $scope->authorizedViewerProject($source,7,$metric)===$viewer;
    $checks['Legacy boolean access API remains compatible']=
        $scope->authorizesViewer($source,7,$metric);
    $result=$dash->bundle('institution.traffic','1.0.0',7);
    $checks['Approved viewer receives existing aggregate']=
        is_array($result)&&($result['widgets'][0]['value']??null)===42.5;
    $viewerActorRef=hash_hmac('sha256','query|project|'.$viewer.'|education.rates@1.0.0',SMAI_PSEUDONYM_KEY);
    $sourceActorRef=hash_hmac('sha256','query|project|'.$source.'|education.rates@1.0.0',SMAI_PSEUDONYM_KEY);
    $checks['Exactly one privacy write on approved viewer dashboard']=count($wpdb->privacyWrites)===1;
    $checks['Privacy write charged to delegated viewers own project']=
        isset($wpdb->privacyWrites[0])&&str_contains($wpdb->privacyWrites[0],$viewerActorRef);
    $checks['Dashboard privacy write does not charge source owner']=
        isset($wpdb->privacyWrites[0])&&!str_contains($wpdb->privacyWrites[0],$sourceActorRef);
    $checks['Privacy read isolated by the viewers own project']=
        count($wpdb->privacyReads)===1&&str_contains($wpdb->privacyReads[0],$viewerActorRef);

    $wpdb->privacyLedgerBroken=true;
    $wpdb->privacyWrites=[];
    $blocked=$dash->bundle('institution.traffic','1.0.0',7);
    $checks['Unavailable privacy ledger cannot disclose aggregate']=
        is_array($blocked)&&($blocked['widgets'][0]['status']??null)==='suppressed'
        &&($blocked['widgets'][0]['value']??'not-null')===null;
    $checks['Broken privacy ledger creates no new allowance']=$wpdb->privacyWrites===[];

    $failed=0;
    foreach($checks as $label=>$ok){
        echo ($ok?'PASS':'FAIL').' V12.3 delegated project '.$label.PHP_EOL;
        if(!$ok)$failed++;
    }
    echo 'CF05 V12.3 delegated privacy accounting: '.(count($checks)-$failed).'/'.count($checks).PHP_EOL;
    exit($failed===0?0:1);
}
