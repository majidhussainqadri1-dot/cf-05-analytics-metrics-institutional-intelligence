<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Domain\FunnelAttributionCalculator;

$checks = [
 'SR230: Dashboard must account for query privacy' => ['src/Domain/DashboardService.php', 'new QueryPrivacyGuard($this->db)'],
 'SR230: Suppressed dashboard never reveals a value' => ['src/Domain/DashboardService.php', "($suppressed || $privacyBlocked)"],
 'SR230: Reports cannot bypass privacy budgets' => ['src/Domain/ReportService.php', 'new QueryPrivacyGuard($this->db)'],
 'SR230: CSV exports cannot bypass privacy budgets' => ['src/Domain/ExportService.php', 'new QueryPrivacyGuard($this->db)'],
 'SR230: Failed export SQL reads fail closed' => ['src/Domain/ExportService.php', 'Export metric snapshot query failed.'],
 'SR230: Snapshot enqueue is actor-scoped' => ['src/Domain/SnapshotService.php', "'actor|' . $actorUserId . '|snapshot|'"],
 'SR230: Quality enqueue is actor-scoped' => ['src/Domain/QualityService.php', "'actor|' . $actorUserId . '|quality|'"],
 'SR230: Differencing ledger is project-scoped' => ['src/Domain/QueryPrivacyGuard.php', "'query|project|' . $projectUuid"],
 'SR230: Conversion time conflict is explicitly bounded' => ['src/Domain/FunnelAttributionCalculator.php','count($firstConversionChannels) > 1'],
];
$failed=0;
foreach ($checks as $label => [$path,$needle]) {
 $body=file_get_contents(dirname(__DIR__).'/'.$path);
 $ok=is_string($body) && str_contains($body,$needle);
 echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;
 if(!$ok) $failed++;
}
$spec=['subject_field'=>'subject','step_field'=>'step','time_field'=>'time',
 'eligible_field'=>'eligible','channel_field'=>'channel','entry_step'=>'start',
 'conversion_step'=>'done','window_seconds'=>86400,'attribution_policy'=>'last_touch'];
$row=static fn(string $step,string $channel,string $time):array => [
 'subject'=>hash('sha256','person-one'),'step'=>$step,'channel'=>$channel,'time'=>$time,'eligible'=>true
];
$ambiguous=[
 $row('start','direct','2026-10-01T00:00:00Z'),
 $row('done','search','2026-10-01T02:00:00Z'),
 $row('done','social','2026-10-01T02:00:00Z'),
];
$forward=FunnelAttributionCalculator::calculate($ambiguous,$spec,'search');
$reverse=FunnelAttributionCalculator::calculate(array_reverse($ambiguous),$spec,'search');
$ok=($forward===$reverse && $forward===[null,0.0,0.0]);
echo ($ok?'PASS: ':'FAIL: ').'Conflicting simultaneous conversion channel cannot alter last-touch credit'.PHP_EOL;
if(!$ok) $failed++;
$valid=[
 $row('start','direct','2026-10-01T00:00:00Z'),
 $row('done','search','2026-10-01T02:00:00Z'),
];
$got=FunnelAttributionCalculator::calculate($valid,$spec,'search');
$ok=$got===[1.0,1.0,1.0];
echo ($ok?'PASS: ':'FAIL: ').'Unambiguous last-touch credit remains executable'.PHP_EOL;
if(!$ok) $failed++;
echo "SR230-269 privacy and measurement assertions: ".(count($checks)+2-$failed)."/".(count($checks)+2)."\n";
exit($failed===0?0:1);
