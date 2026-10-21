<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Sabri\AnalyticsIntelligence\Domain\Future40Engine;
use Sabri\AnalyticsIntelligence\Domain\FutureFeatureRegistry;

$ids=FutureFeatureRegistry::ids();
if(count($ids)!==40)throw new RuntimeException('Exactly 40 governed Future-40 feature IDs required.');
$pass=0;$failed=[];
foreach($ids as $id) {
    // Forty independent executable negative tests. Every feature, including
    // advisory/simulation handlers, must reject nested restricted data.
    $rejected=false;
    try {
        Future40Engine::evaluate($id, ['governed_aggregate'=>['nested'=>[
            'private_key'=>'synthetic-sentinel-must-not-enter-feature'
        ]]]);
    } catch (\InvalidArgumentException $error) {
        $rejected=str_contains($error->getMessage(),'Restricted field');
    }
    if($rejected){$pass++;echo "PASS SR470–509 FUT privacy {$id}\n";}
    else {$failed[]=$id;echo "FAIL SR470–509 FUT privacy {$id}\n";}
}
echo "SR470–509: {$pass}/40 independently executed feature-boundary cases passed.\n";
if($failed!==[])throw new RuntimeException('Future-40 sensitive-input gates missing: '.implode(',',$failed));
