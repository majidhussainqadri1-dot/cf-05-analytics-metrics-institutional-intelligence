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
if "if ($wpdb->query('START TRANSACTION') === false)" not in queue or "if ($wpdb->query('COMMIT') === false)" not in queue:e.append('job lease transaction is not fail-closed')
if "preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,99}$/', $workerId)" not in queue:e.append('job queue permits invalid/anonymous lease owner identity')
if "if (!$this->queue->complete" not in runner:e.append('job runner ignores completion persistence failure')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Infrastructure/runtime invariants check passed.')
