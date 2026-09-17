#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

# QueryPrivacyGuard: keyed fingerprints, serialized privacy accounting, fail-closed persistence.
p=root/'src/Domain/QueryPrivacyGuard.php'; s=p.read_text(encoding='utf-8')
needle="""        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $differencingFloor = max($minimum, (int) ($controls['differencing_floor'] ?? $minimum));
"""
rep="""        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_query_privacy_key_missing', 'Query privacy protection is unavailable.', ['status' => 503]);
        }
        $privacyKey = SMAI_PSEUDONYM_KEY;
        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $differencingFloor = max($minimum, (int) ($controls['differencing_floor'] ?? $minimum));
"""
if needle not in s: raise SystemExit('privacy key insertion target missing')
s=s.replace(needle,rep,1)
s=s.replace("$dimensionHashes[(string) $name] = hash('sha256', Json::canonical($value));","$dimensionHashes[(string) $name] = hash_hmac('sha256', Json::canonical($value), $privacyKey);",1)
s=s.replace("'dimensions_fingerprint' => hash('sha256', Json::canonical($dimensions)),","'dimensions_fingerprint' => hash_hmac('sha256', Json::canonical($dimensions), $privacyKey),",1)
s=s.replace("$actorRef = hash('sha256', 'query|' . $actorUserId . '|' . $projectUuid . '|' . $metricId . '@' . $metricVersion);\n        $table = $this->db->table('idempotency_keys');",
"$actorRef = hash_hmac('sha256', 'query|' . $actorUserId . '|' . $projectUuid . '|' . $metricId . '@' . $metricVersion, $privacyKey);\n        $table = $this->db->table('idempotency_keys');\n        $wpdb = $this->db->wpdb();\n        $lockName = 'smai_privacy_' . substr(hash_hmac('sha256', $actorRef, $privacyKey), 0, 48);\n        $lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lockName));\n        if ($lock !== 1) {\n            return new WP_Error('smai_privacy_lock_unavailable', 'Query privacy accounting is temporarily unavailable.', ['status' => 503]);\n        }\n        try {",1)
s=s.replace("        $requestHash = hash('sha256', Json::canonical([","        $requestHash = hash_hmac('sha256', Json::canonical([",1)
s=s.replace("            'dimensions' => $dimensions,\n        ]));\n        $idempotencyKey = hash('sha256', 'privacy-query|' . $actorRef . '|' . $requestHash);",
"            'dimensions' => $dimensions,\n        ]), $privacyKey);\n        $idempotencyKey = hash_hmac('sha256', 'privacy-query|' . $actorRef . '|' . $requestHash, $privacyKey);",1)
old="""        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,response_json,status_code,state,expires_at,created_at,updated_at) VALUES (%s,'privacy-query',%s,%s,%s,200,'completed',%s,%s,%s)",
            $idempotencyKey,
            $actorRef,
            $requestHash,
            Json::canonical($current),
            gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            $now,
            $now
        ));

        return true;
"""
new="""        $stored = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (idempotency_key,scope,actor_ref,request_hash,response_json,status_code,state,expires_at,created_at,updated_at) VALUES (%s,'privacy-query',%s,%s,%s,200,'completed',%s,%s,%s)",
            $idempotencyKey,
            $actorRef,
            $requestHash,
            Json::canonical($current),
            gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            $now,
            $now
        ));
        if ($stored === false) {
            return new WP_Error('smai_privacy_evidence_unavailable', 'Query privacy accounting could not be persisted.', ['status' => 503]);
        }
        if ($stored === 0) {
            $existingHash = $wpdb->get_var($wpdb->prepare("SELECT request_hash FROM `{$table}` WHERE idempotency_key=%s AND scope='privacy-query' AND actor_ref=%s AND state='completed'", $idempotencyKey, $actorRef));
            if (!is_string($existingHash) || !hash_equals($requestHash, $existingHash)) {
                return new WP_Error('smai_privacy_evidence_conflict', 'Query privacy accounting conflicted with existing evidence.', ['status' => 503]);
            }
        }
        return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
"""
if old not in s: raise SystemExit('privacy insert target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# MetricQueryService: never emit an unkeyed dimension fingerprint.
p=root/'src/Domain/MetricQueryService.php'; s=p.read_text(encoding='utf-8')
old="""        $fingerprint = defined('SMAI_PSEUDONYM_KEY') && is_string(SMAI_PSEUDONYM_KEY) && strlen(SMAI_PSEUDONYM_KEY) >= 32
            ? hash_hmac('sha256', $dimensionsJson, SMAI_PSEUDONYM_KEY)
            : hash('sha256', $dimensionsJson);
"""
new="""        if (!defined('SMAI_PSEUDONYM_KEY') || !is_string(SMAI_PSEUDONYM_KEY) || strlen(SMAI_PSEUDONYM_KEY) < 32) {
            return new WP_Error('smai_query_privacy_key_missing', 'Metric result was withheld because keyed privacy evidence is unavailable.', ['status' => 503]);
        }
        $fingerprint = hash_hmac('sha256', $dimensionsJson, SMAI_PSEUDONYM_KEY);
"""
if old not in s: raise SystemExit('metric fingerprint target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# MetricCatalog: registration and audit evidence must be one transaction.
p=root/'src/Domain/MetricCatalog.php'; s=p.read_text(encoding='utf-8')
old="""        $now = $this->db->now();
        $inserted = $this->db->wpdb()->insert($table, [
"""
new="""        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_metric_transaction_failed', 'Metric registration transaction could not start.', ['status' => 500]);
        }
        $inserted = $wpdb->insert($table, [
"""
if old not in s: raise SystemExit('metric transaction start target missing')
s=s.replace(old,new,1)
s=s.replace("        $id = (int) $this->db->wpdb()->insert_id;\n        if (!$this->audit->log(\n","        $id = (int) $wpdb->insert_id;\n        if (!$this->audit->logInOpenTransaction(\n",1)
s=s.replace("""            $this->db->wpdb()->delete($table, ['id' => $id, 'state' => 'draft', 'row_version' => 1]);
            return new WP_Error('smai_metric_audit_failed', 'Metric registration was rolled back because audit evidence was unavailable.', ['status' => 503]);
        }
        return ['id' => $id, 'status' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
""",
"""            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_metric_audit_failed', 'Metric registration was rolled back because audit evidence was unavailable.', ['status' => 503]);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_metric_commit_failed', 'Metric registration could not be committed.', ['status' => 500]);
        }
        return ['id' => $id, 'status' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];
""",1)
s=s.replace("""        if ($inserted !== 1) {
            return new WP_Error('smai_metric_store_failed', 'Metric definition could not be stored.', ['status' => 500]);
        }
""","""        if ($inserted !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_metric_store_failed', 'Metric definition could not be stored.', ['status' => 500]);
        }
""",1)
p.write_text(s,encoding='utf-8')

(root/'scripts/metric-privacy-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
q=(r/'src/Domain/QueryPrivacyGuard.php').read_text(encoding='utf-8')
m=(r/'src/Domain/MetricQueryService.php').read_text(encoding='utf-8')
c=(r/'src/Domain/MetricCatalog.php').read_text(encoding='utf-8')
errors=[]
if "hash('sha256', Json::canonical($value))" in q or "dimensions_fingerprint' => hash('sha256'" in q: errors.append('query privacy fingerprints are unkeyed')
if 'SELECT GET_LOCK' not in q or 'SELECT RELEASE_LOCK' not in q: errors.append('privacy budget accounting is not serialized')
if "$stored === false" not in q or 'smai_privacy_evidence_unavailable' not in q: errors.append('privacy evidence persistence can fail open')
if ": hash('sha256', $dimensionsJson)" in m: errors.append('metric audit fingerprint has unkeyed fallback')
if "logInOpenTransaction" not in c: errors.append('metric registration audit is not transactional')
if errors: print('\\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Metric privacy invariants check passed.')
""",encoding='utf-8')
p=root/'scripts/qa.sh'; s=p.read_text(encoding='utf-8'); needle='python3 scripts/event-privacy-invariants-check.py\n'
if 'metric-privacy-invariants-check.py' not in s:
    if needle not in s: raise SystemExit('qa insertion missing')
    p.write_text(s.replace(needle,needle+'python3 scripts/metric-privacy-invariants-check.py\n',1),encoding='utf-8')
(root/'docs/REVIEW-ROUND-13.md').write_text("""# Review Round 13 — Metric Query, Cohort and Privacy Accounting\n\nThe review completed before corrections began, covering metric registration, exact-version query authorization, cohort suppression, differencing resistance, freshness and privacy accounting.\n\n## Confirmed defects\n1. Query privacy dimension hashes/fingerprints and actor references used plain SHA-256, allowing low-cardinality dictionary analysis of stored privacy evidence.\n2. Metric query audit fingerprint fell back to unkeyed SHA-256 when the pseudonym key was unavailable.\n3. Privacy budget/slice accounting was vulnerable to concurrent read-check-insert races.\n4. A failed privacy-accounting insert was ignored, permitting a result to be disclosed without durable budget evidence.\n5. Metric registration and its audit record were not transactionally atomic; cleanup-after-audit-failure could itself fail.\n\n## Corrections\nAll privacy fingerprints/references are keyed HMACs, per-actor/project/metric accounting is serialized with a bounded database advisory lock, privacy evidence persistence is fail-closed, metric query audit has no unkeyed fallback, and metric registration/audit commit atomically. Permanent QA invariants were added.\n\nNo staging/live claim is made by this source review.\n""",encoding='utf-8')
print('Review 13 corrections applied.')
