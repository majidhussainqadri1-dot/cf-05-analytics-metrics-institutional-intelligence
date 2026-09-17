#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

def edit(path, old, new, count=1):
    p=root/path; s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'target missing: {path}: {old[:120]!r}')
    p.write_text(s.replace(old,new,count),encoding='utf-8')

# Rate limiter must reject the first request beyond the configured limit.
edit('src/Infrastructure/RateLimiter.php',
"ON DUPLICATE KEY UPDATE count_value=IF(count_value<%d,count_value+1,count_value),expires_at=VALUES(expires_at)",
"ON DUPLICATE KEY UPDATE count_value=IF(count_value<=%d,count_value+1,count_value),expires_at=VALUES(expires_at)")

# Runtime activation is security-sensitive and must enforce capability in the domain service,
# not rely only on REST permission callbacks.
p=root/'src/Infrastructure/RuntimeActivationService.php'; s=p.read_text(encoding='utf-8')
for signature in [
    "    public function propose(string $state,string $evidenceHash,string $reason,int $actorUserId):array|WP_Error\n    {\n",
    "    public function approve(string $requestHash,int $actorUserId):array|WP_Error\n    {\n",
    "    public function disable(string $reason,int $actorUserId,bool $foundation=false):array|WP_Error\n    {\n",
]:
    if signature not in s: raise SystemExit('runtime activation method target missing')
    s=s.replace(signature, signature+"        if($actorUserId<1||!user_can($actorUserId,'smai_activate_runtime'))return new WP_Error('smai_activation_forbidden','Runtime activation operation is not authorized.',['status'=>403]);\n",1)
p.write_text(s,encoding='utf-8')

# Job lease acquisition must be a verified transaction and must not create anonymous leases.
p=root/'src/Infrastructure/JobQueue.php'; s=p.read_text(encoding='utf-8')
old="""    public function claim(string $workerId, int $leaseSeconds = 120): ?array
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + max(30, min(900, $leaseSeconds)));
        $wpdb->query('START TRANSACTION');
"""
new="""    public function claim(string $workerId, int $leaseSeconds = 120): ?array
    {
        $workerId = trim($workerId);
        if ($workerId === '' || strlen($workerId) > 100 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,99}$/', $workerId) !== 1) {
            return null;
        }
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + max(30, min(900, $leaseSeconds)));
        if ($wpdb->query('START TRANSACTION') === false) {
            return null;
        }
"""
if old not in s: raise SystemExit('job claim start target missing')
s=s.replace(old,new,1)
s=s.replace("""            if (!is_array($row)) {
                $wpdb->query('COMMIT');
                return null;
            }
""","""            if (!is_array($row)) {
                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                }
                return null;
            }
""",1)
s=s.replace("""            $wpdb->query('COMMIT');
            $row['state'] = 'running';
""","""            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return null;
            }
            $row['state'] = 'running';
""",1)
p.write_text(s,encoding='utf-8')

# Worker must not silently ignore inability to persist successful completion.
p=root/'src/Infrastructure/JobRunner.php'; s=p.read_text(encoding='utf-8')
old="""                $result = $this->dispatch((string) $job['job_type'], (array) $job['payload']);
                $this->queue->complete((string) $job['job_uuid'], $worker, $result);
"""
new="""                $result = $this->dispatch((string) $job['job_type'], (array) $job['payload']);
                if (!$this->queue->complete((string) $job['job_uuid'], $worker, $result)) {
                    $this->queue->fail((string) $job['job_uuid'], $worker, 'job_completion_persist_failed', 'The governed worker could not persist successful completion.');
                }
"""
if old not in s: raise SystemExit('job completion target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

(root/'scripts/infrastructure-runtime-invariants-check.py').write_text("""#!/usr/bin/env python3
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
if e:print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Infrastructure/runtime invariants check passed.')
""",encoding='utf-8')
qa=root/'scripts/qa.sh'; q=qa.read_text(encoding='utf-8'); needle='python3 scripts/future40-authorization-invariants-check.py\n'
if 'infrastructure-runtime-invariants-check.py' not in q:
    if needle not in q: raise SystemExit('qa target missing')
    qa.write_text(q.replace(needle,needle+'python3 scripts/infrastructure-runtime-invariants-check.py\n',1),encoding='utf-8')

(root/'docs/REVIEW-ROUND-19.md').write_text("""# Review Round 19 — Infrastructure, Runtime Activation and Worker Reliability\n\nThe complete runtime-gate, activation, rate-limit, job-lease, worker, activation/upgrade and uninstall infrastructure surface was audited before corrections began.\n\n## Confirmed defects\n1. Rate limiting stopped incrementing at exactly the configured limit and then returned `count <= limit`, so every later request in the same bucket remained permitted instead of being rejected.\n2. `RuntimeActivationService::propose`, `approve` and `disable` trusted the REST layer for authorization; direct service invocation did not enforce `smai_activate_runtime`.\n3. `JobQueue::claim()` did not validate the worker identity, did not fail closed when `START TRANSACTION` failed, and returned a claimed lease without verifying that `COMMIT` succeeded.\n4. `JobRunner` ignored a failed success-acknowledgement (`JobQueue::complete()`), allowing a completed side effect to remain leased/runnable and later be retried after lease expiry without an immediate controlled retry state.\n\n## Corrections\nThe limiter now advances the first over-limit request into a denied state; runtime activation mutations enforce capability at the service boundary; job claims require a bounded worker identity and verified transaction/commit; worker completion persistence failure is converted to a controlled retry/dead-letter path where the lease is still owned; permanent QA invariants were added.\n\nNo staging/live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 19 corrections applied.')
