<?php
declare(strict_types=1);

/**
 * V12.3 CF05-FR-010/013/016: raw warehouse evidence and snapshot
 * revision cannot be synthesized after corrupt JSON or failed SQL.
 */
require __DIR__ . '/bootstrap.php';
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix='wp_';
        public string $last_error='';
    }
}

use Sabri\AnalyticsIntelligence\Domain\SnapshotService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

$wpdb=new wpdb();
$service=new SnapshotService(new Database($wpdb));
$reflect=new ReflectionClass(SnapshotService::class);
$decode=$reflect->getMethod('decodeSourceRow');
$revision=$reflect->getMethod('nextSnapshotRevision');
$checks=[];
$goodTime=gmdate('Y-m-d H:i:s',time()-3600);
$fixture=['row_json'=>'{"member_id":"anon","count":3,"flag":true,"amount":0.5,"empty":null}','effective_from'=>$goodTime];
$good=$decode->invoke($service,$fixture);
$checks['canonical object and past UTC time decoded']=is_array($good)
    &&$good['count']===3&&$good['flag']===true&&$good['amount']===0.5&&array_key_exists('empty',$good);
$checks['real scalar row retained for aggregation']=count($good)===5;

function v123_raises(callable $fn):bool{
    try{$fn();}catch(RuntimeException $e){return true;}
    return false;
}
$badCases=[
    'missing row evidence'=>['effective_from'=>$goodTime],
    'missing time evidence'=>['row_json'=>'{"count":3}'],
    'row evidence is not a record'=>false,
    'source row scalar object'=>['row_json'=>123,'effective_from'=>$goodTime],
    'invalid JSON syntax'=>['row_json'=>'{"count":','effective_from'=>$goodTime],
    'JSON list instead of object'=>['row_json'=>'[1,2]','effective_from'=>$goodTime],
    'JSON null instead of object'=>['row_json'=>'null','effective_from'=>$goodTime],
    'empty object cannot be a counted row'=>['row_json'=>'{}','effective_from'=>$goodTime],
    'whitespace-only JSON'=>['row_json'=>'   ','effective_from'=>$goodTime],
    'numeric root field not an allowed dataset key'=>['row_json'=>'{"0":"numeric"}','effective_from'=>$goodTime],
    'nested object not a scalar dataset field'=>['row_json'=>'{"nested":{"secret":"value"}}','effective_from'=>$goodTime],
    'nested array not a scalar dataset field'=>['row_json'=>'{"nested":[1,2]}','effective_from'=>$goodTime],
    'uppercase field not approved'=>['row_json'=>'{"Field":2}','effective_from'=>$goodTime],
    'pathological long field name'=>['row_json'=>'{"'.str_repeat('x',65).'":2}','effective_from'=>$goodTime],
    'too many fields'=>['row_json'=>json_encode(array_fill_keys(array_map(static fn($i)=>'key'.$i,range(1,101)),1)),'effective_from'=>$goodTime],
    'source row too large'=>['row_json'=>'{"payload":"'.str_repeat('a',1048576).'"}','effective_from'=>$goodTime],
    'invalid leap day'=>['row_json'=>'{"count":1}','effective_from'=>'2026-02-29 12:00:00'],
    'noncanonical relative time'=>['row_json'=>'{"count":1}','effective_from'=>'tomorrow'],
    'ISO offset is not canonical warehouse UTC'=>['row_json'=>'{"count":1}','effective_from'=>gmdate('c',time()-3600)],
    'terminal newline on time'=>['row_json'=>'{"count":1}','effective_from'=>$goodTime."\n"],
    'future source watermark'=>['row_json'=>'{"count":1}','effective_from'=>gmdate('Y-m-d H:i:s',time()+3600)],
];
foreach ($badCases as $label=>$row) {
    $checks['Source rejected: '.$label]=v123_raises(static fn()=>$decode->invoke($service,$row));
}
$shortSkew=$decode->invoke($service,['row_json'=>'{"count":1}','effective_from'=>gmdate('Y-m-d H:i:s',time()+100)]);
$checks['Small permitted clock skew is not silently invalidated']=$shortSkew['count']===1;

$wpdb->last_error='';
$checks['No prior row plus clean SQL gives first revision']=$revision->invoke($service,null)===1;
$checks['Valid prior revision 1 increments to 2']=$revision->invoke($service,['snapshot_revision'=>'1'])===2;
$checks['Valid prior revision 10 increments to 11']=$revision->invoke($service,['snapshot_revision'=>10])===11;
$badPrevious=[
    'query false'=>false,
    'query malformed scalar'=>'invalid',
    'malformed row array'=>[],
    'missing previous revision'=>['id'=>4],
    'revision zero'=>['snapshot_revision'=>'0'],
    'negative revision'=>['snapshot_revision'=>'-2'],
    'leading zero revision'=>['snapshot_revision'=>'02'],
    'suffix in revision'=>['snapshot_revision'=>'12garbage'],
    'revision noninteger'=>['snapshot_revision'=>2.1],
    'revision overflow'=>['snapshot_revision'=>str_repeat('9',24)],
];
foreach($badPrevious as $label=>$prior){
    $checks['Previous rejected: '.$label]=v123_raises(static fn()=>$revision->invoke($service,$prior));
}
$wpdb->last_error='simulated SQL error';
$checks['Failed previous SQL read must not create revision one']=
    v123_raises(static fn()=>$revision->invoke($service,null));

$failures=0;
foreach($checks as $label=>$ok){
    echo ($ok?'PASS':'FAIL').' V12.3 snapshot source/revision '.$label.PHP_EOL;
    if(!$ok)$failures++;
}
echo 'CF05 V12.3 snapshot source and revision integrity: '.(count($checks)-$failures).'/'.count($checks).PHP_EOL;
exit($failures===0?0:1);
