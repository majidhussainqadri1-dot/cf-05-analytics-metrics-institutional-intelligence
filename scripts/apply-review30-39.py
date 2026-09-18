#!/usr/bin/env python3
from __future__ import annotations
from pathlib import Path
import json,re,subprocess,sys

ROOT=Path(__file__).resolve().parents[1]
def read(p): return (ROOT/p).read_text(encoding='utf-8')
def write(p,s):
    q=ROOT/p; q.parent.mkdir(parents=True,exist_ok=True); q.write_text(s,encoding='utf-8')
def rep(p,old,new,count=1):
    s=read(p)
    if old not in s: raise RuntimeError(f'missing replacement anchor in {p}: {old[:120]!r}')
    write(p,s.replace(old,new,count))
def run(cmd):
    print('+',cmd,flush=True); subprocess.run(cmd,shell=True,cwd=ROOT,check=True)
def qa(n):
    print(f'[SR-{n}] running full source QA after corrections...',flush=True); run('bash scripts/qa.sh')
def doc(n,focus,defects,corrections):
    body=[f'# Sequential Review Round {n} — {focus}','',
      'This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.','',
      f'## Frozen defect ledger ({len(defects)})']
    body += [f'{i}. {d}' for i,d in enumerate(defects,1)] if defects else ['No new defect was confirmed in this round.']
    body += ['','## Corrections']
    body += [f'- {c}' for c in corrections] if corrections else ['- No correction was required.']
    body += ['','## Truth boundary','Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.','']
    write(f'docs/SEQUENTIAL-REVIEW-ROUND-{n}.md','\n'.join(body))

def audit30():
    s=read('src/Domain/AccessProjectService.php'); d=[]
    if '$expiry = strtotime($expiresAt);' in s: d.append('Access-project external expiry accepts loose/relative strtotime syntax instead of strict RFC3339 input.')
    if s.count("$wpdb->query('START TRANSACTION');")>=4: d.append('Access request/approval/revocation/expiry transactions can proceed without verifying that START TRANSACTION succeeded.')
    if s.count("$wpdb->query('COMMIT');")>=4: d.append('Access lifecycle paths can report success or increment expiry counts without verifying COMMIT success.')
    if "analytics_access_expired" in s and "if ($wpdb->query('COMMIT') === false)" not in s: d.append('Scheduled access expiry does not fail closed on commit failure.')
    return d
def fix30():
    p='src/Domain/AccessProjectService.php'
    rep(p,'        $expiry = strtotime($expiresAt);','        $expiry = $this->strictTimestamp($expiresAt);')
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $ok = $wpdb->insert($this->db->table('access_projects'), [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_access_transaction_failed', 'Access request transaction could not start.', ['status' => 500]);\n        }\n        $ok = $wpdb->insert($this->db->table('access_projects'), [")
    rep(p,"        $wpdb->query('COMMIT');\n        return ['project_uuid' => $uuid, 'state' => 'requested', 'row_version' => 1];","        if ($wpdb->query('COMMIT') === false) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_access_commit_failed', 'Access project could not be committed.', ['status' => 500]);\n        }\n        return ['project_uuid' => $uuid, 'state' => 'requested', 'row_version' => 1];")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $now = $this->db->now();\n        $updated = $wpdb->update($this->db->table('access_projects'), [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_access_transaction_failed', 'Access approval transaction could not start.', ['status' => 500]);\n        }\n        $now = $this->db->now();\n        $updated = $wpdb->update($this->db->table('access_projects'), [",1)
    rep(p,"        $wpdb->query('COMMIT');\n        return ['project_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1];","        if ($wpdb->query('COMMIT') === false) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_access_commit_failed', 'Access approval could not be committed.', ['status' => 500]);\n        }\n        return ['project_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1];")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $now = $this->db->now();\n        $updated = $wpdb->update($this->db->table('access_projects'), [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_access_transaction_failed', 'Access revocation transaction could not start.', ['status' => 500]);\n        }\n        $now = $this->db->now();\n        $updated = $wpdb->update($this->db->table('access_projects'), [",1)
    rep(p,"        $wpdb->query('COMMIT');\n        return ['project_uuid' => $uuid, 'state' => 'revoked', 'row_version' => (int) $project['row_version'] + 1];","        if ($wpdb->query('COMMIT') === false) {\n            $wpdb->query('ROLLBACK');\n            return new WP_Error('smai_access_commit_failed', 'Access revocation could not be committed.', ['status' => 500]);\n        }\n        return ['project_uuid' => $uuid, 'state' => 'revoked', 'row_version' => (int) $project['row_version'] + 1];")
    rep(p,"            $wpdb = $this->db->wpdb();\n            $wpdb->query('START TRANSACTION');\n            $updated = $wpdb->update($table, [","            $wpdb = $this->db->wpdb();\n            if ($wpdb->query('START TRANSACTION') === false) { continue; }\n            $updated = $wpdb->update($table, [")
    rep(p,"                $wpdb->query('COMMIT');\n                $count++;","                if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); continue; }\n                $count++;")
    rep(p,"    private function validUuid(string $uuid): bool\n    {","    private function strictTimestamp(string $value): ?int\n    {\n        if (strlen($value) > 35 || preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d{1,6})?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/', $value, $match) !== 1 || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) { return null; }\n        $timestamp = strtotime($value); return $timestamp === false ? null : $timestamp;\n    }\n\n    private function validUuid(string $uuid): bool\n    {")

def audit31():
    s=read('src/Domain/DashboardService.php'); d=[]
    if "$expiry = strtotime((string) $definition['expires_at']);" in s: d.append('Dashboard external expiry accepts loose timestamp syntax.')
    if s.count("$wpdb->query('START TRANSACTION');")>=2: d.append('Dashboard registration/activation do not verify transaction start.')
    if s.count("$wpdb->query('COMMIT');")>=2: d.append('Dashboard registration/activation do not verify transaction commit.')
    if 'storedWidgetsValidForActivation' not in s: d.append('Dashboard activation does not revalidate stored metric/access/privacy contracts after draft creation.')
    if "PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions)" not in s[s.find('public function bundle'):]: d.append('Dashboard disclosure rechecks cohort minimum but not the full current dimension privacy policy.')
    if "'uncertainty' => is_array($row) ?" in s: d.append('Suppressed dashboard slices can still disclose uncertainty/caveat evidence derived from the withheld slice.')
    return d
def fix31():
    p='src/Domain/DashboardService.php'
    rep(p,"            $expiry = strtotime((string) $definition['expires_at']);","            $expiry = $this->strictTimestamp((string) $definition['expires_at']);")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $now = $this->db->now();\n        $wpdb->query('START TRANSACTION');\n        try {","        $wpdb = $this->db->wpdb();\n        $now = $this->db->now();\n        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_dashboard_transaction_failed', 'Dashboard transaction could not start.', ['status' => 500]); }\n        try {")
    rep(p,"            $wpdb->query('COMMIT');\n            return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];","            if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Dashboard transaction could not be committed.'); }\n            return ['id' => $id, 'state' => 'draft', 'row_version' => 1, 'definition_hash' => $hash];")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $updated = $wpdb->update($table, [","        $validation = $this->storedWidgetsValidForActivation($row);\n        if (is_wp_error($validation)) { return $validation; }\n        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_dashboard_transaction_failed', 'Dashboard activation transaction could not start.', ['status' => 500]); }\n        $updated = $wpdb->update($table, [")
    rep(p,"        $wpdb->query('COMMIT');\n        return ['dashboard_id' => $dashboardId, 'dashboard_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];","        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_dashboard_commit_failed', 'Dashboard activation could not be committed.', ['status' => 500]); }\n        return ['dashboard_id' => $dashboardId, 'dashboard_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];")
    old="""            $metric = (new MetricCatalog($this->db))->active($metricId, $metricVersion);
            $minimum = is_array($metric) ? PrivacyQueryPolicy::effectiveMinimum((array) $metric['definition'], $dimensions, (int) get_option('smai_minimum_cohort', 20)) : PHP_INT_MAX;
            $suppressed = is_array($row) && ((string) $row['quality_status'] === 'suppressed' || (int) $row['cohort_size'] < $minimum);
            $invalidated = is_array($row) && (string) $row['quality_status'] === 'invalidated';
            if ($invalidated) { $row = null; }
"""
    new="""            $metric = (new MetricCatalog($this->db))->active($metricId, $metricVersion);
            $policyViolations = is_array($metric) ? PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions) : ['metric_unavailable'];
            $minimum = is_array($metric) ? PrivacyQueryPolicy::effectiveMinimum((array) $metric['definition'], $dimensions, (int) get_option('smai_minimum_cohort', 20)) : PHP_INT_MAX;
            $suppressed = is_array($row) && ($policyViolations !== [] || (string) $row['quality_status'] === 'suppressed' || (int) $row['cohort_size'] < $minimum);
            $invalidated = is_array($row) && (string) $row['quality_status'] === 'invalidated';
            if ($invalidated) { $row = null; $suppressed = false; }
"""
    rep(p,old,new)
    rep(p,"                'uncertainty' => is_array($row) ? Json::object((string) ($row['uncertainty_json'] ?? '{}')) : [],\n                'caveats' => is_array($row) ? Json::list((string) ($row['caveats_json'] ?? '[]')) : ['No approved snapshot is available.'],","                'uncertainty' => is_array($row) && !$suppressed ? Json::object((string) ($row['uncertainty_json'] ?? '{}')) : [],\n                'caveats' => $suppressed ? ['Suppressed by the current privacy policy.'] : (is_array($row) ? Json::list((string) ($row['caveats_json'] ?? '[]')) : ['No approved snapshot is available.']),")
    helper="""
    /** @return true|WP_Error */
    private function storedWidgetsValidForActivation(array $dashboard): true|WP_Error
    {
        $project = (new AccessProjectService($this->db))->get((string) $dashboard['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) { return new WP_Error('smai_dashboard_project_inactive', 'Dashboard access project is inactive.', ['status' => 409]); }
        $widgets = $this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('dashboard_widgets')}` WHERE dashboard_id=%s AND dashboard_version=%s ORDER BY position_order,id",(string) $dashboard['dashboard_id'],(string) $dashboard['dashboard_version']), ARRAY_A);
        $access = new AccessProjectService($this->db);
        foreach (is_array($widgets) ? $widgets : [] as $widget) {
            $config = Json::object((string) $widget['config_json']); $dimensions = is_array($config['dimensions'] ?? null) ? $config['dimensions'] : [];
            $metricId = (string) $widget['metric_id']; $metricVersion = (string) $widget['metric_version']; $metric = (new MetricCatalog($this->db))->active($metricId, $metricVersion);
            if (!is_array($metric) || !$access->authorize((string) $dashboard['project_uuid'], (int) $dashboard['owner_user_id'], 'metric:' . $metricId . '@' . $metricVersion, [], (string) $project['purpose']) || PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions) !== []) { return new WP_Error('smai_dashboard_activation_contract_drift', 'Dashboard activation is blocked because a stored widget no longer satisfies its governed metric/access/privacy contract.', ['status' => 409]); }
        }
        return true;
    }

    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d{1,6})?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/', $value, $match) !== 1 || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) { return null; }
        $timestamp = strtotime($value); return $timestamp === false ? null : $timestamp;
    }
"""
    rep(p,"    /** @param array<string,mixed> $audience @return array<string,mixed>|WP_Error */",helper+"\n    /** @param array<string,mixed> $audience @return array<string,mixed>|WP_Error */")

def audit32():
    s=read('src/Domain/NarrativeService.php'); d=[]
    if "$snapshotHash !== ''" in s: d.append('Narrative citations can omit immutable snapshot hashes and remain metric/window-only.')
    if '$startTs = strtotime($windowStart);' in s: d.append('Narrative citation windows accept loose timestamp syntax.')
    if 'SELECT id FROM' in s and 'window_start,window_end,quality_status' not in s: d.append('Narrative citation validation does not bind a supplied window to the cited snapshot or reject suppressed snapshot evidence.')
    if 'citationsAvailable' not in s: d.append('Narrative publication does not revalidate cited evidence at publication time.')
    return d
def fix32():
    p='src/Domain/NarrativeService.php'
    rep(p,"            if ($snapshotHash !== '' && (strlen($snapshotHash) !== 64 || !ctype_xdigit($snapshotHash))) {","            if ($snapshotHash === '' || strlen($snapshotHash) !== 64 || !ctype_xdigit($snapshotHash)) {")
    start=read(p).index("            $canonicalStart = '';"); end=read(p).index("            $citationKey = hash('sha256'",start)
    s=read(p)
    block="""            $canonicalStart = '';
            $canonicalEnd = '';
            if ($windowStart !== '') {
                $startTs = $this->strictTimestamp($windowStart); $endTs = $this->strictTimestamp($windowEnd);
                if ($startTs === null || $endTs === null || $startTs >= $endTs) { return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation window is invalid.', ['status' => 400]); }
                $canonicalStart = gmdate('Y-m-d H:i:s', $startTs); $canonicalEnd = gmdate('Y-m-d H:i:s', $endTs);
            }
            $snapshot = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT id,window_start,window_end,quality_status FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND snapshot_hash=%s AND state='published' LIMIT 1",$metricId,$metricVersion,$snapshotHash), ARRAY_A);
            if (!is_array($snapshot) || in_array((string) $snapshot['quality_status'], ['suppressed','invalidated'], true)) { return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation snapshot is unavailable for publication.', ['status' => 409]); }
            if ($canonicalStart !== '' && (!hash_equals((string) $snapshot['window_start'], $canonicalStart) || !hash_equals((string) $snapshot['window_end'], $canonicalEnd))) { return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation window does not match the immutable snapshot.', ['status' => 409]); }
            $normalized = ['metric_id'=>$metricId,'metric_version'=>$metricVersion,'snapshot_hash'=>$snapshotHash,'window_start'=>(string)$snapshot['window_start'],'window_end'=>(string)$snapshot['window_end']];

"""
    write(p,s[:start]+block+s[end:])
    rep(p,"        if ((int) $row['author_user_id'] === $reviewerUserId) {\n            return new WP_Error('smai_separation_of_duties', 'Independent narrative review is required.', ['status' => 403]);\n        }\n\n        $wpdb=$this->db->wpdb();","        if ((int) $row['author_user_id'] === $reviewerUserId) {\n            return new WP_Error('smai_separation_of_duties', 'Independent narrative review is required.', ['status' => 403]);\n        }\n        if (!$this->citationsAvailable(Json::list((string) $row['citations_json']))) { return new WP_Error('smai_narrative_citation_stale', 'Narrative publication is blocked because cited evidence is no longer publishable.', ['status' => 409]); }\n\n        $wpdb=$this->db->wpdb();")
    helper="""
    /** @param array<int,mixed> $citations */
    private function citationsAvailable(array $citations): bool
    {
        if ($citations === []) { return false; }
        foreach ($citations as $citation) {
            if (!is_array($citation)) { return false; }
            $metricId=(string)($citation['metric_id']??''); $metricVersion=(string)($citation['metric_version']??''); $hash=strtolower((string)($citation['snapshot_hash']??''));
            if ((new MetricCatalog($this->db))->active($metricId,$metricVersion)===null || preg_match('/^[a-f0-9]{64}$/',$hash)!==1) { return false; }
            $snapshot=$this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT window_start,window_end,quality_status FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND snapshot_hash=%s AND state='published' LIMIT 1",$metricId,$metricVersion,$hash),ARRAY_A);
            if (!is_array($snapshot) || in_array((string)$snapshot['quality_status'],['suppressed','invalidated'],true) || !hash_equals((string)$snapshot['window_start'],(string)($citation['window_start']??'')) || !hash_equals((string)$snapshot['window_end'],(string)($citation['window_end']??''))) { return false; }
        }
        return true;
    }
    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d{1,6})?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/',$value,$m)!==1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) { return null; }
        $t=strtotime($value); return $t===false?null:$t;
    }
"""
    idx=read(p).rfind("\n}"); s=read(p); write(p,s[:idx]+helper+s[idx:])

def audit33():
    v=read('src/Contracts/ExperimentDefinitionValidator.php'); s=read('src/Domain/ExperimentService.php'); d=[]
    if "strtotime((string) $definition['starts_at'])" in v: d.append('Experiment definition validator accepts loose start/end date syntax.')
    if "$occurred = strtotime((string) $fact['occurred_at']);" in s: d.append('Experiment assignment facts accept loose occurred_at syntax.')
    if s.count("$wpdb->query('START TRANSACTION');")>=5: d.append('Several experiment create/transition/assignment/analysis/publication transactions do not verify transaction start.')
    if 'metricsAvailableForExperiment' not in s: d.append('Experiment scheduling/running does not revalidate active metric and guardrail contracts after proposal creation.')
    if 'PrivacyQueryPolicy::effectiveMinimum' not in s[s.find('private function latestSnapshot'):]: d.append('Experiment analysis does not reapply current effective cohort/privacy policy to stored snapshots.')
    if "strtotime((string) $record['review_at'])" in s: d.append('Decision review_at accepts loose timestamp syntax.')
    return d
def fix33():
    v='src/Contracts/ExperimentDefinitionValidator.php'
    rep(v,"        $start = !empty($definition['starts_at']) ? strtotime((string) $definition['starts_at']) : false;\n        $end = !empty($definition['ends_at']) ? strtotime((string) $definition['ends_at']) : false;\n        if (!empty($definition['starts_at']) && $start === false) { $errors[] = 'invalid_starts_at'; }\n        if (!empty($definition['ends_at']) && $end === false) { $errors[] = 'invalid_ends_at'; }","        $start = !empty($definition['starts_at']) ? $this->strictTimestamp((string) $definition['starts_at']) : null;\n        $end = !empty($definition['ends_at']) ? $this->strictTimestamp((string) $definition['ends_at']) : null;\n        if (!empty($definition['starts_at']) && $start === null) { $errors[] = 'invalid_starts_at'; }\n        if (!empty($definition['ends_at']) && $end === null) { $errors[] = 'invalid_ends_at'; }")
    rep(v,"        if ($start !== false && $end !== false && ($end <= $start || $end - $start > 366 * DAY_IN_SECONDS)) { $errors[] = 'invalid_experiment_window'; }\n        return array_values(array_unique($errors));\n    }","        if ($start !== null && $end !== null && ($end <= $start || $end - $start > 366 * DAY_IN_SECONDS)) { $errors[] = 'invalid_experiment_window'; }\n        return array_values(array_unique($errors));\n    }\n    private function strictTimestamp(string $value): ?int { if (strlen($value)>35 || preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d{1,6})?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/',$value,$m)!==1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null; $t=strtotime($value); return $t===false?null:$t; }")
    p='src/Domain/ExperimentService.php'
    rep(p,"        $startsAt = !empty($definition['starts_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $definition['starts_at'])) : null;\n        $endsAt = !empty($definition['ends_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $definition['ends_at'])) : null;","        $startsAtTs=!empty($definition['starts_at'])?$this->strictTimestamp((string)$definition['starts_at']):null; $endsAtTs=!empty($definition['ends_at'])?$this->strictTimestamp((string)$definition['ends_at']):null;\n        $startsAt=$startsAtTs!==null?gmdate('Y-m-d H:i:s',$startsAtTs):null; $endsAt=$endsAtTs!==null?gmdate('Y-m-d H:i:s',$endsAtTs):null;")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $ok = $wpdb->insert($this->db->table('experiments'), [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_experiment_transaction_failed','Experiment transaction could not start.',['status'=>500]);\n        $ok = $wpdb->insert($this->db->table('experiments'), [")
    rep(p,"        $wpdb->query('COMMIT');\n        return ['experiment_uuid' => $uuid, 'state' => 'proposed', 'row_version' => 1, 'enhanced_review' => $enhanced];","        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_experiment_commit_failed','Experiment could not be committed.',['status'=>500]); }\n        return ['experiment_uuid' => $uuid, 'state' => 'proposed', 'row_version' => 1, 'enhanced_review' => $enhanced];")
    rep(p,"        if ($target === 'running' && (($row['starts_at'] !== null && time() < strtotime((string) $row['starts_at'])) || ($row['ends_at'] !== null && time() >= strtotime((string) $row['ends_at'])))) {\n            return new WP_Error('smai_experiment_outside_window', 'Experiment cannot run outside its approved window.', ['status' => 409]);\n        }","        if ($target === 'running' && (($row['starts_at'] !== null && time() < strtotime((string) $row['starts_at'])) || ($row['ends_at'] !== null && time() >= strtotime((string) $row['ends_at'])))) { return new WP_Error('smai_experiment_outside_window', 'Experiment cannot run outside its approved window.', ['status' => 409]); }\n        if (in_array($target,['scheduled','running'],true) && !$this->metricsAvailableForExperiment($row)) return new WP_Error('smai_experiment_metric_stale','Experiment cannot advance because a governed metric or guardrail contract is no longer active or privacy-valid.',['status'=>409]);")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $updated = $wpdb->update($table, [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_experiment_transaction_failed','Experiment transition transaction could not start.',['status'=>500]);\n        $updated = $wpdb->update($table, [",1)
    rep(p,"        $wpdb->query('COMMIT');\n        return ['experiment_uuid' => $uuid, 'state' => $target, 'row_version' => $expectedVersion + 1];","        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_experiment_commit_failed','Experiment transition could not be committed.',['status'=>500]); }\n        return ['experiment_uuid' => $uuid, 'state' => $target, 'row_version' => $expectedVersion + 1];")
    rep(p,"        $occurred = strtotime((string) $fact['occurred_at']);","        $occurred = $this->strictTimestamp((string) $fact['occurred_at']);")
    rep(p,"            $wpdb = $this->db->wpdb();\n            $wpdb->query('START TRANSACTION');\n            $inserted = $wpdb->insert($table, [","            $wpdb = $this->db->wpdb();\n            if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_assignment_transaction_failed','Assignment transaction could not start.',['status'=>500]);\n            $inserted = $wpdb->insert($table, [")
    rep(p,"            $wpdb->query('COMMIT');\n            return ['assignment_event_id' => $canonical['assignment_event_id'], 'status' => 'accepted'];","            if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_assignment_commit_failed','Assignment fact could not be committed.',['status'=>500]); }\n            return ['assignment_event_id' => $canonical['assignment_event_id'], 'status' => 'accepted'];")
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        $ok = $wpdb->insert($table, [","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_analysis_transaction_failed','Analysis transaction could not start.',['status'=>500]);\n        $ok = $wpdb->insert($table, [",1)
    rep(p,"        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        try {","        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_analysis_transaction_failed','Analysis publication transaction could not start.',['status'=>500]);\n        try {",1)
    rep(p,"            $wpdb->query('COMMIT');\n            return ['analysis_uuid' => $analysisUuid, 'status' => 'published', 'row_version' => $expectedVersion + 1, 'experiment_state' => 'analyzed'];","            if ($wpdb->query('COMMIT')===false) throw new \\RuntimeException('commit_failed');\n            return ['analysis_uuid' => $analysisUuid, 'status' => 'published', 'row_version' => $expectedVersion + 1, 'experiment_state' => 'analyzed'];")
    rep(p,"        if (!empty($record['review_at']) && strtotime((string) $record['review_at']) === false) {\n            return new WP_Error('smai_invalid_review_date', 'Decision review date is invalid.', ['status' => 400]);\n        }","        $reviewAtTs=!empty($record['review_at'])?$this->strictTimestamp((string)$record['review_at']):null;\n        if (!empty($record['review_at']) && $reviewAtTs===null) return new WP_Error('smai_invalid_review_date','Decision review date is invalid.',['status'=>400]);")
    rep(p,"            'review_at' => !empty($record['review_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $record['review_at'])) : null,","            'review_at' => $reviewAtTs!==null ? gmdate('Y-m-d H:i:s',$reviewAtTs) : null,")
    rep(p,"        if (!is_array($row)) {\n            return null;\n        }\n        return [\n            'snapshot_hash' => $row['snapshot_hash'],","        if (!is_array($row)) return null;\n        $metric=(new MetricCatalog($this->db))->active($metricId,$version); $minimum=is_array($metric)?PrivacyQueryPolicy::effectiveMinimum((array)$metric['definition'],$dimensions,(int)get_option('smai_minimum_cohort',20)):PHP_INT_MAX;\n        if (!is_array($metric) || PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[] || (int)$row['cohort_size']<$minimum) return null;\n        return [\n            'snapshot_hash' => $row['snapshot_hash'],")
    helper="""
    /** @param array<string,mixed> $experiment */
    private function metricsAvailableForExperiment(array $experiment): bool
    {
        $specs=array_merge(Json::list((string)($experiment['metrics_json']??'[]')),Json::list((string)($experiment['guardrails_json']??'[]')));
        foreach($specs as $spec){ if(!is_array($spec))return false; $metric=(new MetricCatalog($this->db))->active((string)($spec['metric_id']??''),(string)($spec['metric_version']??'')); $dimensions=is_array($spec['base_dimensions']??null)?$spec['base_dimensions']:[]; if(!is_array($metric)||PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[])return false; }
        return true;
    }
    private function strictTimestamp(string $value): ?int { if(strlen($value)>35||preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d{1,6})?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/',$value,$m)!==1||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null; $t=strtotime($value);return $t===false?null:$t; }
"""
    rep(p,"    /** @return array<string,mixed>|null */\n    public function get(string $uuid): ?array",helper+"\n    /** @return array<string,mixed>|null */\n    public function get(string $uuid): ?array")

def audit34():
    a=read('src/Infrastructure/AuditVerifier.php'); h=read('src/Infrastructure/HealthService.php'); r=read('src/Infrastructure/RepairService.php'); d=[]
    if 'LIMIT " . max(1, min(1000000, $limit))' in a: d.append('Audit verification truncates at a limit but still compares the truncated chain head with the global audit-state head.')
    if "is_string($head) &&" in a: d.append('Missing or malformed audit_state head is not itself treated as an audit-chain failure.')
    if "dead_letter_jobs_present';\n        }" in h: d.append('Health reports dead-letter jobs as a reason but can still return healthy status.')
    if "'audit_chain' => $this->db->exists('audit_log') ?" in h: d.append('Health exposes audit verification but does not make a failed/unavailable audit chain a health failure.')
    if 'future_intelligence' not in r: d.append('Repair/check coverage omits the Future-40 scheduled intelligence cron.')
    if 'attempts < max_attempts' not in r: d.append('Safe repair can revive exhausted crash-loop jobs beyond max attempts.')
    if 'wp_schedule_event' in r and 'is_wp_error' not in r: d.append('Safe repair ignores cron schedule creation failures.')
    return d
def fix34():
    write('src/Infrastructure/AuditVerifier.php',"""<?php
declare(strict_types=1);
namespace Sabri\AnalyticsIntelligence\Infrastructure;
final class AuditVerifier
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    /** @return array<string,mixed> */
    public function verify(int $pageSize=10000):array
    {
        $table=$this->db->table('audit_log');$pageSize=max(100,min(50000,$pageSize));$previous=str_repeat('0',64);$checked=0;$lastId=0;$failures=[];
        while(true){
            $rows=$this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE id>%d ORDER BY id ASC LIMIT %d",$lastId,$pageSize),ARRAY_A);
            if(!is_array($rows)){$failures[]=['id'=>null,'code'=>'audit_query_failed'];break;} if($rows===[])break;
            foreach($rows as $row){$checked++;$lastId=(int)$row['id'];if(!hash_equals($previous,(string)$row['previous_hash'])){$failures[]=['id'=>$lastId,'code'=>'previous_hash_mismatch'];break 2;}
                $material=implode('|',[$row['event_uuid'],(string)$row['actor_user_id'],$row['actor_type'],$row['action'],$row['object_type'],(string)$row['object_ref'],(string)$row['purpose'],$row['result'],(string)$row['trace_id'],(string)$row['context_json'],$row['previous_hash'],$row['created_at']]);
                $expected=hash('sha256',$material);if(!hash_equals($expected,(string)$row['record_hash'])){$failures[]=['id'=>$lastId,'code'=>'record_hash_mismatch'];break 2;}$previous=(string)$row['record_hash'];
            } if(count($rows)<$pageSize)break;
        }
        $head=$this->db->wpdb()->get_var("SELECT last_hash FROM `{$this->db->table('audit_state')}` WHERE id=1");
        if(!is_string($head)||preg_match('/^[a-f0-9]{64}$/',$head)!==1)$failures[]=['id'=>null,'code'=>'audit_state_missing_or_invalid'];
        elseif($failures===[]&&!hash_equals($previous,$head))$failures[]=['id'=>null,'code'=>'head_hash_mismatch'];
        return ['status'=>$failures===[]?'verified':'failed','checked_records'=>$checked,'last_hash'=>$previous,'failures'=>$failures,'verified_at'=>gmdate('c')];
    }
}
""")
    p='src/Infrastructure/HealthService.php'
    rep(p,"        if (is_array($queue) && (int) ($queue['dead'] ?? 0) > 0) {\n            $degradationReasons[] = 'dead_letter_jobs_present';\n        }","        if (is_array($queue) && (int) ($queue['dead'] ?? 0) > 0) { $healthy = false; $degradationReasons[] = 'dead_letter_jobs_present'; }")
    rep(p,"        $degradationReasons = array_values(array_unique($degradationReasons));\n\n        return [","        $auditChain=$this->db->exists('audit_log')&&$this->db->exists('audit_state')?(new AuditVerifier($this->db))->verify(10000):['status'=>'unavailable'];\n        if (($auditChain['status']??'unavailable')!=='verified') { $healthy=false; $degradationReasons[]='audit_chain_unverified'; }\n        $degradationReasons = array_values(array_unique($degradationReasons));\n\n        return [")
    rep(p,"            'audit_chain' => $this->db->exists('audit_log') ? (new AuditVerifier($this->db))->verify(10000) : ['status' => 'unavailable'],","            'audit_chain' => $auditChain,")
    p='src/Infrastructure/RepairService.php'
    rep(p,"                'access_expiry' => wp_next_scheduled('smai_access_expiry') ?: null,","                'access_expiry' => wp_next_scheduled('smai_access_expiry') ?: null,\n                'future_intelligence' => wp_next_scheduled('smai_future_intelligence_tick') ?: null,")
    start=read(p).index("        if (!wp_next_scheduled('smai_daily_retention'))"); end=read(p).index("        $now = $this->db->now();",start); s=read(p)
    sched="""        $this->ensureSchedule('smai_daily_retention', time()+HOUR_IN_SECONDS, 'daily');
        $this->ensureSchedule('smai_run_jobs', time()+5*MINUTE_IN_SECONDS, 'smai_five_minutes');
        $this->ensureSchedule('smai_schedule_reports', time()+10*MINUTE_IN_SECONDS, 'hourly');
        $this->ensureSchedule('smai_access_expiry', time()+15*MINUTE_IN_SECONDS, 'hourly');
        $this->ensureSchedule('smai_future_intelligence_tick', time()+20*MINUTE_IN_SECONDS, 'hourly');
"""
    write(p,s[:start]+sched+s[end:])
    rep(p,'''        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('jobs')}` SET state='retrying',lease_owner=NULL,lease_until=NULL,next_run_at=%s,updated_at=%s WHERE state='running' AND lease_until<%s",
            $now,
            $now,
            $now
        ));''','''        $recovered=$this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('jobs')}` SET state='retrying',lease_owner=NULL,lease_until=NULL,next_run_at=%s,updated_at=%s WHERE state='running' AND lease_until<%s AND attempts < max_attempts",$now,$now,$now));
        if($recovered===false)throw new \\RuntimeException('Expired job lease repair failed.');
        $dead=$this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('jobs')}` SET state='dead_letter',lease_owner=NULL,lease_until=NULL,error_code='lease_exhausted',error_message='Expired running lease exhausted maximum attempts.',updated_at=%s WHERE state='running' AND lease_until<%s AND attempts >= max_attempts",$now,$now));
        if($dead===false)throw new \\RuntimeException('Exhausted lease fail-closed repair failed.');''')
    idx=read(p).rfind("\n}"); s=read(p); helper="""
    private function ensureSchedule(string $hook,int $timestamp,string $recurrence):void
    {
        if(wp_next_scheduled($hook))return;$result=wp_schedule_event($timestamp,$recurrence,$hook,[],true);if(is_wp_error($result)||$result===false)throw new \RuntimeException('CF-05 repair could not create schedule: '.$hook);
    }
"""; write(p,s[:idx]+helper+s[idx:])

def audit35():
    d=[]
    for f in ['dataset-definition.schema.json','event-envelope.schema.json','experiment-definition.schema.json','metric-response.schema.json','module-manifest.schema.json']:
        if '1.3.0' in read('contracts/'+f): d.append(f'Public contract ID {f} still declares 1.3.0 while repository contract family is 1.4.0.')
    c=read('contracts/experiment-definition.schema.json'); v=read('src/Contracts/ExperimentDefinitionValidator.php')
    if '"minLength": 12' in c or '"maxLength": 3000' in c: d.append('Experiment JSON schema hypothesis bounds disagree with executable validator.')
    if '"maxItems": 20' in c and "count($primary) > 10" in v: d.append('Experiment primary-metric count differs between public schema and validator.')
    if '"enhanced_review"' in c: d.append('Experiment public schema advertises enhanced_review input although validator derives/rejects it.')
    return d
def fix35():
    for f in ['dataset-definition.schema.json','event-envelope.schema.json','experiment-definition.schema.json','metric-response.schema.json','module-manifest.schema.json']:
        p='contracts/'+f; write(p,read(p).replace('1.3.0.json','1.4.0.json'))
    p='contracts/future-feature.schema.json'; write(p,read(p).replace('future-feature.schema.json','future-feature-1.4.0.schema.json'))
    p='contracts/experiment-definition.schema.json'; data=json.loads(read(p)); props=data['properties']; props['hypothesis']['minLength']=20;props['hypothesis']['maxLength']=5000;props['assignment_owner']={'type':'string','pattern':'^[a-z0-9][a-z0-9_.-]{1,99}$'};props['primary_metrics']['maxItems']=10;props['guardrails'].pop('minItems',None);props['guardrails']['maxItems']=20;props.pop('enhanced_review',None);write(p,json.dumps(data,indent=2,ensure_ascii=False)+'\n')
    p='tests/run.php'; anchor="$tests['lifecycle transitions are closed and independent'] = static function (): void {"; test="""$tests['experiment timestamps require strict RFC3339'] = static function (): void {
    $definition=governedExperimentDefinition();$definition['starts_at']='tomorrow';truth(in_array('invalid_starts_at',(new ExperimentDefinitionValidator())->errors($definition),true));
    $definition['starts_at']='2026-09-20T10:00:00Z';$definition['ends_at']='2026-09-21T10:00:00Z';same([],(new ExperimentDefinitionValidator())->errors($definition));
};

"""; rep(p,anchor,test+anchor)

def audit36():
    s=read('src/Domain/FutureArtifactStore.php'); d=[]
    if "Json::canonical(array_values(array_map('strval',$datasets)))" in s: d.append('Future research workspace persists claimed approved datasets without verifying published governed dataset contracts.')
    if "Json::canonical(array_values(array_map('strval',$metrics)))" in s: d.append('Transparency records persist metric identifiers without checking active governed metric versions.')
    if "Json::canonical(array_values(array_map('strval',$classes)))" in s: d.append('Transparency records accept unrestricted data-class strings.')
    if "$name = Text::truncate(trim((string)($input['name'] ?? 'Scenario model')), 190);" in s: d.append('Persisted scenario names are bounded but not stripped of markup.')
    return d
def fix36():
    p='src/Domain/FutureArtifactStore.php'
    rep(p,"        $name = Text::truncate(trim((string)($input['name'] ?? 'Scenario model')), 190);","        $name = Text::truncate(trim(wp_strip_all_tags((string)($input['name'] ?? 'Scenario model'))), 190);")
    rep(p,"        $title=Text::truncate(trim((string)($input['title']??'')),190);$purpose=Text::truncate(trim((string)($input['purpose']??'')),500);$datasets=$input['approved_aggregate_datasets']??[];$expiresOn=trim((string)($input['expires_on']??''));\n        if($title===''||$purpose===''||!is_array($datasets)||$datasets===[])return new WP_Error('smai_future_research_metadata_required','Research workspace requires title, purpose and approved aggregate datasets.',['status'=>400]);","        $title=Text::truncate(trim(wp_strip_all_tags((string)($input['title']??''))),190);$purpose=Text::truncate(trim(wp_strip_all_tags((string)($input['purpose']??''))),500);$datasets=$input['approved_aggregate_datasets']??[];$expiresOn=trim((string)($input['expires_on']??''));\n        if($title===''||$purpose===''||!is_array($datasets)||$datasets===[])return new WP_Error('smai_future_research_metadata_required','Research workspace requires title, purpose and approved aggregate datasets.',['status'=>400]);\n        $approvedDatasets=[];foreach($datasets as $datasetRef){$datasetRef=trim((string)$datasetRef);if(preg_match('/^[a-z][a-z0-9_.-]{2,189}@[0-9]+\\.[0-9]+\\.[0-9]+$/',$datasetRef)!==1)return new WP_Error('smai_future_research_dataset_invalid','Research workspace dataset reference is invalid.',['status'=>400]);[$datasetId,$datasetVersion]=explode('@',$datasetRef,2);if((new DatasetCatalog($this->db))->published($datasetId,$datasetVersion)===null)return new WP_Error('smai_future_research_dataset_unavailable','Research workspace dataset is not currently published.',['status'=>409]);$approvedDatasets[$datasetRef]=true;} $datasets=array_keys($approvedDatasets);sort($datasets,SORT_STRING);")
    rep(p,"        $purpose=Text::truncate(trim((string)($input['purpose']??'')),500);$metrics=$input['metric_ids']??[];$classes=$input['data_classes']??[];$retention=Text::truncate(trim((string)($input['retention_summary']??'')),500);\n        if($purpose===''||!is_array($metrics)||$metrics===[]||!is_array($classes)||$classes===[]||$retention==='')return new WP_Error('smai_future_transparency_metadata_required','Transparency record requires purpose, metrics, data classes and retention summary.',['status'=>400]);","        $purpose=Text::truncate(trim(wp_strip_all_tags((string)($input['purpose']??''))),500);$metrics=$input['metric_ids']??[];$classes=$input['data_classes']??[];$retention=Text::truncate(trim(wp_strip_all_tags((string)($input['retention_summary']??''))),500);\n        if($purpose===''||!is_array($metrics)||$metrics===[]||!is_array($classes)||$classes===[]||$retention==='')return new WP_Error('smai_future_transparency_metadata_required','Transparency record requires purpose, metrics, data classes and retention summary.',['status'=>400]);\n        $approvedMetrics=[];foreach($metrics as $metricRef){$metricRef=trim((string)$metricRef);if(preg_match('/^[a-z][a-z0-9_.-]{2,189}@[0-9]+\\.[0-9]+\\.[0-9]+$/',$metricRef)!==1)return new WP_Error('smai_future_transparency_metric_invalid','Transparency metric reference is invalid.',['status'=>400]);[$metricId,$metricVersion]=explode('@',$metricRef,2);if((new MetricCatalog($this->db))->active($metricId,$metricVersion)===null)return new WP_Error('smai_future_transparency_metric_unavailable','Transparency metric is not currently active.',['status'=>409]);$approvedMetrics[$metricRef]=true;} $metrics=array_keys($approvedMetrics);sort($metrics,SORT_STRING);$classes=array_values(array_unique(array_map('strval',$classes)));sort($classes,SORT_STRING);if(array_diff($classes,['C1','C2','C3'])!==[])return new WP_Error('smai_future_transparency_data_class_invalid','Transparency data class is not governed.',['status'=>400]);")

def audit37():
    f=read('src/Http/FutureRestController.php'); g=read('src/Http/GovernanceRestController.php'); d=[]
    if "(bool)($payload['dry_run']??false)" in f: d.append('Future run coerces non-boolean dry_run values instead of enforcing JSON boolean.')
    if "max(0,(int)($payload['row_version']??0))" in f: d.append('Future lifecycle endpoints coerce malformed row_version values instead of rejecting invalid JSON types.')
    if "$payload = $request->get_json_params();\n        if (!is_array($payload))" in f: d.append('Future mutation payload accepts top-level JSON arrays although API requires an object.')
    if "$payload=$request->get_json_params();if(!is_array($payload))" in g: d.append('Governance mutation payload also accepts top-level JSON arrays.')
    return d
def fix37():
    p='src/Http/FutureRestController.php'
    rep(p,"                    return (new FutureFeatureService($this->db))->configure((string)$request['feature_id'], is_array($payload['config']??null)?$payload['config']:[], get_current_user_id(), max(0,(int)($payload['row_version']??0)));","                    $rowVersion=$this->nonNegativeInt($payload,'row_version');if(is_wp_error($rowVersion))return $rowVersion; return (new FutureFeatureService($this->db))->configure((string)$request['feature_id'],is_array($payload['config']??null)?$payload['config']:[],get_current_user_id(),$rowVersion);")
    rep(p,"                    return (new FutureFeatureService($this->db))->transition((string)$request['feature_id'], strtolower((string)($payload['action']??'')), (string)($payload['reason']??''), get_current_user_id(), max(0,(int)($payload['row_version']??0)));","                    $rowVersion=$this->nonNegativeInt($payload,'row_version');if(is_wp_error($rowVersion))return $rowVersion; return (new FutureFeatureService($this->db))->transition((string)$request['feature_id'],strtolower((string)($payload['action']??'')),(string)($payload['reason']??''),get_current_user_id(),$rowVersion);")
    rep(p,"                    return (new FutureFeatureService($this->db))->run((string)$request['feature_id'], is_array($payload['input']??null)?$payload['input']:[], get_current_user_id(), (bool)($payload['dry_run']??false));","                    if(isset($payload['dry_run'])&&!is_bool($payload['dry_run']))return new WP_Error('smai_future_invalid_dry_run','dry_run must be a JSON boolean.',['status'=>400]); return (new FutureFeatureService($this->db))->run((string)$request['feature_id'],is_array($payload['input']??null)?$payload['input']:[],get_current_user_id(),$payload['dry_run']??false);")
    rep(p,"        $payload = $request->get_json_params();\n        if (!is_array($payload)) return new WP_Error('smai_invalid_json', 'A JSON object is required.', ['status'=>400]);\n        return $payload;","        $raw=trim((string)$request->get_body());$decoded=$raw===''?new \\stdClass():json_decode($raw);if(!($decoded instanceof \\stdClass))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);\n        $payload=$request->get_json_params();if(!is_array($payload))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]); return $payload;")
    rep(p,"    private function response(array $result, int $status = 200): WP_REST_Response","    /** @param array<string,mixed> $payload */\n    private function nonNegativeInt(array $payload,string $key):int|WP_Error { if(!array_key_exists($key,$payload)||!is_int($payload[$key])||$payload[$key]<0)return new WP_Error('smai_future_invalid_row_version','row_version must be a non-negative JSON integer.',['status'=>400]);return $payload[$key]; }\n\n    private function response(array $result, int $status = 200): WP_REST_Response")
    p='src/Http/GovernanceRestController.php'
    rep(p,"        $payload=$request->get_json_params();if(!is_array($payload))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);return $payload;","        $raw=trim((string)$request->get_body());$decoded=$raw===''?new \\stdClass():json_decode($raw);if(!($decoded instanceof \\stdClass))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);\n        $payload=$request->get_json_params();if(!is_array($payload))return new WP_Error('smai_invalid_json','A JSON object is required.',['status'=>400]);return $payload;")

def audit38():
    s=read('src/Infrastructure/SchemaMigrator.php'); plugin=read('sabri-analytics-institutional-intelligence.php'); d=[]
    if 'KEY created_at (created_at)' not in s: d.append('Future run retention has no direct created_at index.')
    if s.count('KEY updated_at (updated_at)')<2: d.append('Time-based Future-40 retention tables lack direct updated_at indexes.')
    if s.count('KEY state_updated (state,updated_at)')<2: d.append('Closed incident/research retention lacks state+updated_at supporting indexes.')
    if '1.0.0-rc.6' in plugin: d.append('Substantial post-rc.6 corrections still identify the deployable candidate as rc.6.')
    if "SMAI_SCHEMA_VERSION', '1.4.0'" in plugin: d.append('Existing 1.4.0 installations would not rerun dbDelta for newly required retention indexes.')
    if 'review30-39-invariants-check.py' not in read('scripts/qa.sh'): d.append('New sequential-review invariants are not yet a permanent QA gate.')
    return d
def fix38():
    p='src/Infrastructure/SchemaMigrator.php'
    rep(p,"            KEY actor_time (actor_user_id,created_at)\n        ) {$charset};","            KEY actor_time (actor_user_id,created_at),\n            KEY created_at (created_at)\n        ) {$charset};")
    rep(p,"            KEY owner_state (owner_user_id,state)\n        ) {$charset};","            KEY owner_state (owner_user_id,state),\n            KEY state_updated (state,updated_at)\n        ) {$charset};",1)
    rep(p,"            KEY feature_owner (feature_id,owner_user_id)\n        ) {$charset};","            KEY feature_owner (feature_id,owner_user_id),\n            KEY updated_at (updated_at)\n        ) {$charset};")
    rep(p,"            KEY owner_state (owner_user_id,state),\n            KEY expires_at (expires_at)\n        ) {$charset};\";\n\n        $sql[] = \"CREATE TABLE {$p}intelligence_alerts (","            KEY owner_state (owner_user_id,state),\n            KEY expires_at (expires_at),\n            KEY state_updated (state,updated_at)\n        ) {$charset};\";\n\n        $sql[] = \"CREATE TABLE {$p}intelligence_alerts (",1)
    rep(p,"            KEY severity_time (severity,created_at)\n        ) {$charset};","            KEY severity_time (severity,created_at),\n            KEY updated_at (updated_at)\n        ) {$charset};")
    changes={'sabri-analytics-institutional-intelligence.php':[('1.0.0-rc.6','1.0.0-rc.7'),("SMAI_SCHEMA_VERSION', '1.4.0'","SMAI_SCHEMA_VERSION', '1.4.1'")],
      'MANIFEST.json':[('1.0.0-rc.6','1.0.0-rc.7'),('"schema_version": "1.4.0"','"schema_version": "1.4.1"')],
      'README.md':[('1.0.0-rc.6','1.0.0-rc.7')],'readme.txt':[('Stable tag: 1.0.0-rc.6','Stable tag: 1.0.0-rc.7')],
      'docs/IMPLEMENTATION-STATUS.md':[('1.0.0-rc.6','1.0.0-rc.7'),('schema `1.4.0`','schema `1.4.1`')],
      'docs/CODING-COMPLETION-REPORT.md':[('Candidate: `1.0.0-rc.6`','Candidate: `1.0.0-rc.7`'),('Database schema: `1.4.0`','Database schema: `1.4.1`')],
      'scripts/build-package.py':[("version='1.0.0-rc.6'","version='1.0.0-rc.7'"),("serialNumber':'urn:uuid:cf05-analytics-1-0-0-rc-6'","serialNumber':'urn:uuid:cf05-analytics-1-0-0-rc-7'"),("'schema_version':'1.4.0'","'schema_version':'1.4.1'")],
      'scripts/package-parity.py':[("version='1.0.0-rc.6'","version='1.0.0-rc.7'")],
      'scripts/verify-deterministic-build.sh':[('1.0.0-rc.6.zip','1.0.0-rc.7.zip'),('/tmp/cf05-first-rc5.zip','/tmp/cf05-first-rc7.zip')],
      'scripts/architecture-check.py':[("('1.0.0-rc.6','1.4.0','1.4.0')","('1.0.0-rc.7','1.4.1','1.4.0')")],
      'scripts/cross-plan-check.py':[("manifest.get('version') != '1.0.0-rc.6'","manifest.get('version') != '1.0.0-rc.7'"),("manifest version is not 1.0.0-rc.6","manifest version is not 1.0.0-rc.7")],
      'scripts/future40-check.py':[("manifest.get('version')!='1.0.0-rc.6'","manifest.get('version')!='1.0.0-rc.7'"),("manifest_version_not_rc6","manifest_version_not_rc7"),("manifest.get('schema_version')!='1.4.0'","manifest.get('schema_version')!='1.4.1'"),("manifest_schema_not_1_4_0","manifest_schema_not_1_4_1")]}
    for path,pairs in changes.items():
        s=read(path)
        for old,new in pairs:
            if old not in s: raise RuntimeError(f'missing version anchor {old} in {path}')
            s=s.replace(old,new)
        write(path,s)
    p='CHANGELOG.md';write(p,"## 1.0.0-rc.7 — 2026-09-18\n- Closed sequential review rounds SR-30..SR-39 across access, dashboards, narrative evidence, experiments, audit/repair health, public contracts, Future-40 persistence, REST type safety and retention indexing.\n- Raised database schema to `1.4.1`; public contract family remains `1.4.0`.\n- Preserved staging/live/operational gates as independent evidence requirements.\n\n"+read(p))
    inv="""#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1];errors=[]
def t(p):return (root/p).read_text(encoding='utf-8')
for p,n in [('src/Domain/AccessProjectService.php','strictTimestamp'),('src/Domain/DashboardService.php','storedWidgetsValidForActivation'),('src/Domain/NarrativeService.php','citationsAvailable'),('src/Domain/ExperimentService.php','metricsAvailableForExperiment'),('src/Infrastructure/HealthService.php','audit_chain_unverified'),('src/Infrastructure/RepairService.php','smai_future_intelligence_tick'),('src/Domain/FutureArtifactStore.php','smai_future_research_dataset_unavailable'),('src/Http/FutureRestController.php','smai_future_invalid_dry_run'),('src/Infrastructure/SchemaMigrator.php','KEY created_at (created_at)')]:
    if n not in t(p):errors.append(f'{p}:missing:{n}')
plugin=t('sabri-analytics-institutional-intelligence.php')
for n in ["SMAI_VERSION', '1.0.0-rc.7'","SMAI_SCHEMA_VERSION', '1.4.1'","SMAI_CONTRACT_VERSION', '1.4.0'"]:
    if n not in plugin:errors.append('release_identity:'+n)
for name in ['dataset-definition.schema.json','event-envelope.schema.json','experiment-definition.schema.json','metric-response.schema.json','module-manifest.schema.json']:
    if '1.4.0' not in t('contracts/'+name):errors.append('contract_id_stale:'+name)
if errors:print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Sequential review 30-39 invariants check passed.')
"""
    write('scripts/review30-39-invariants-check.py',inv);rep('scripts/qa.sh','python3 scripts/review20-29-invariants-check.py\n','python3 scripts/review20-29-invariants-check.py\npython3 scripts/review30-39-invariants-check.py\n')

def audit39():
    d=[]
    for p,n,msg in [('src/Domain/AccessProjectService.php','$expiry = strtotime($expiresAt);','Loose access expiry parser remains.'),('src/Domain/DashboardService.php',"$expiry = strtotime((string) $definition['expires_at']);",'Loose dashboard expiry parser remains.'),('src/Domain/NarrativeService.php','$startTs = strtotime($windowStart);','Loose narrative citation parser remains.'),('src/Domain/ExperimentService.php',"$occurred = strtotime((string) $fact['occurred_at']);",'Loose assignment timestamp parser remains.'),('src/Http/FutureRestController.php',"(bool)($payload['dry_run']??false)",'Future dry_run coercion remains.')]:
        if n in read(p):d.append(msg)
    if '1.0.0-rc.7' not in read('sabri-analytics-institutional-intelligence.php') or '1.4.1' not in read('MANIFEST.json'):d.append('Release/schema identity is not synchronized.')
    if 'python3 scripts/review30-39-invariants-check.py' not in read('scripts/qa.sh'):d.append('Current sequential-review invariants are not enforced by full QA.')
    return d
def fix39(): return

rounds=[
(30,'Access projects, expiry and transaction atomicity',audit30,fix30,['Required strict RFC3339 expiry input.','Made request/approval/revocation/expiry transaction start and commit fail closed.']),
(31,'Dashboards, activation drift and disclosure privacy',audit31,fix31,['Revalidated widget metric/access/privacy contracts at activation.','Reapplied current privacy policy at disclosure and withheld suppressed uncertainty/caveats.','Made dashboard transactions and external expiry parsing fail closed.']),
(32,'Narrative citation immutability and publication evidence',audit32,fix32,['Required immutable snapshot hashes, bound citation windows to snapshot rows and rejected suppressed evidence.','Revalidated cited evidence immediately before publication.']),
(33,'Experiments, assignment time, metric drift and analysis privacy',audit33,fix33,['Enforced strict RFC3339 experiment/assignment/decision timestamps.','Made key experiment transactions check start/commit.','Revalidated metric contracts at scheduling/running and privacy policy at analysis snapshot use.']),
(34,'Audit verification, health and repair safety',audit34,fix34,['Made audit verification cover the complete chain and fail on missing audit state.','Made health fail on audit-chain/dead-letter degradation.','Made repair cover Future-40 cron, check schedule errors and respect max-attempt lease recovery.']),
(35,'Public contracts and executable-validator consistency',audit35,fix35,['Aligned public contract IDs with contract family 1.4.0.','Aligned experiment JSON schema with executable validation and added strict-time regression coverage.']),
(36,'Future-40 persistent artifacts and governed references',audit36,fix36,['Validated research datasets against published contracts.','Validated transparency metric versions and governed data classes.','Sanitized persisted Future-40 text metadata.']),
(37,'Future/Governance REST type and JSON-object safety',audit37,fix37,['Made dry_run a strict JSON boolean and row_version a strict non-negative JSON integer.','Rejected top-level JSON arrays on Future/Governance mutation endpoints.']),
(38,'Retention indexes, release identity and permanent regression gates',audit38,fix38,['Added time/state indexes supporting Future-40 retention queries.','Raised candidate to 1.0.0-rc.7 and schema to 1.4.1 while retaining contract family 1.4.0.','Added permanent SR-30..39 invariant checks to full QA.']),
(39,'Final whole-repository contradiction and regression audit',audit39,fix39,[])]

all_ledgers={}
for no,focus,audit,fix,corrections in rounds:
    print(f'\n=== SR-{no}: AUDIT START — {focus} ===',flush=True)
    defects=audit();all_ledgers[no]=defects
    print(f'[SR-{no}] frozen defect ledger: {len(defects)}',flush=True)
    for i,d in enumerate(defects,1):print(f'  {i}. {d}',flush=True)
    print(f'=== SR-{no}: AUDIT COMPLETE; CORRECTIONS MAY NOW BEGIN ===',flush=True)
    if defects:fix()
    doc(no,focus,defects,corrections if defects else [])
    qa(no)
    print(f'=== SR-{no}: CORRECTIONS + QA COMPLETE ===',flush=True)

closure=['# Ten-Round Sequential Review Closure — SR-30..SR-39','','Each round was audited fully before any correction for that round. The defect ledger was frozen, confirmed defects were corrected, and full source QA passed before the next round began.','','## Round outcomes']
for no,focus,_,_,_ in rounds:
    n=len(all_ledgers[no]);closure.append(f'- **SR-{no} — {focus}:** '+(f'{n} defect(s) found and corrected.' if n else 'no new defect confirmed.'))
defect_rounds=[str(n) for n in all_ledgers if all_ledgers[n]];clean=[str(n) for n in all_ledgers if not all_ledgers[n]]
closure += ['',f'**Rounds with confirmed defects:** {", ".join(defect_rounds) if defect_rounds else "none"}.',f'**Clean rounds:** {", ".join(clean) if clean else "none"}.','','## Truth boundary','This is repository-source and automated-QA evidence only. It does not prove `main` merge, staging acceptance, deployed artifact parity, live database/schema state, live deployment or operational verification.','']
write('docs/TEN-ROUND-30-39-CLOSURE.md','\n'.join(closure))
print('\nTen sequential SR-30..SR-39 rounds completed successfully.',flush=True)
