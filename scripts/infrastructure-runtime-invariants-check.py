#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
rate=(r/'src/Infrastructure/RateLimiter.php').read_text(encoding='utf-8')
act=(r/'src/Infrastructure/RuntimeActivationService.php').read_text(encoding='utf-8')
queue=(r/'src/Infrastructure/JobQueue.php').read_text(encoding='utf-8')
runner=(r/'src/Infrastructure/JobRunner.php').read_text(encoding='utf-8')
e=[]
if 'count_value=IF(count_value<=%d,count_value+1,count_value)' not in rate:e.append('rate limiter does not cross the limit into a rejected state')
if act.count("user_can($actorUserId,'smai_activate_runtime')") < 3:e.append('runtime activation service lacks domain-layer authorization on all mutations')
gate=(r/'src/Infrastructure/RuntimeGate.php').read_text(encoding='utf-8')
health=(r/'src/Infrastructure/HealthService.php').read_text(encoding='utf-8')
for token in ["privateConfigurationReady","SMAI_INGESTION_SECRET","SMAI_PSEUDONYM_KEY","SMAI_EXPORT_KEY"]:
    if token not in gate:e.append('runtime_private_configuration_gate_missing:'+token)
if act.count("RuntimeGate::privateConfigurationReady()") < 2:e.append('runtime activation proposal/approval lacks private-configuration gates')
if "'private_configuration_ready' => $privateConfigurationReady" not in health:e.append('health report omits private configuration readiness')
if "if ($wpdb->query('START TRANSACTION') === false)" not in queue or "if ($wpdb->query('COMMIT') === false)" not in queue:e.append('job lease transaction is not fail-closed')
if "preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,99}$/', $workerId)" not in queue:e.append('job queue permits invalid/anonymous lease owner identity')
if "if (!$this->queue->complete" not in runner:e.append('job runner ignores completion persistence failure')
report=(r/'src/Domain/ReportService.php').read_text(encoding='utf-8')
for token in ["private const TYPES","'pipeline.process_event'","'report.run'","in_array($type, self::TYPES, true)"]:
    if token not in queue:e.append('job_queue_allowlist_missing:'+token)
if "RuntimeGate::workerEnabled()" not in report.split('public function scheduleDue(): int',1)[1].split('/**',1)[0]:
    e.append('report_scheduler_runtime_gate_missing')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Infrastructure/runtime invariants check passed.')
