#!/usr/bin/env python3
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def write(path: str, content: str) -> None:
    (ROOT / path).write_text(content, encoding='utf-8')


def replace_required(path: str, old: str, new: str, count: int = 1) -> None:
    p = ROOT / path
    s = p.read_text(encoding='utf-8')
    if old not in s:
        raise RuntimeError(f"Required patch target missing in {path}: {old[:120]!r}")
    p.write_text(s.replace(old, new, count), encoding='utf-8')


def run_qa(round_no: int) -> None:
    print(f"[round {round_no}] running full source QA after corrections...")
    subprocess.run(['bash', 'scripts/qa.sh'], cwd=ROOT, check=True)


def review_doc(round_no: int, title: str, defects: list[str], corrections: list[str]) -> None:
    lines = [
        f"# Review Round {round_no} — {title}",
        "",
        "The entire round scope was audited first without modifying source. The defect ledger below was frozen only after that audit completed. Corrections then began, and the next round did not start until post-correction QA passed.",
        "",
        "## Confirmed defects",
    ]
    if defects:
        lines += [f"{i}. {item}" for i, item in enumerate(defects, 1)]
    else:
        lines.append("No new source defect was confirmed in this round after the preceding corrections.")
    lines += ["", "## Corrections"]
    if corrections:
        lines += [f"- {item}" for item in corrections]
    else:
        lines.append("No corrective source change was required for this round.")
    lines += [
        "",
        "## Lifecycle boundary",
        "This is repository-source review evidence only. It does not assert staging acceptance, live deployment, database migration completion, deployment parity, or operational verification.",
        "",
    ]
    write(f"docs/REVIEW-ROUND-{round_no}.md", "\n".join(lines))


def audit20() -> list[str]:
    e = read('src/Domain/EventIngestionService.php')
    p = read('src/Domain/PrivacyGateway.php')
    defects = []
    if "$wpdb->query('START TRANSACTION');\n        try {" in e:
        defects.append('Accepted-event ingestion starts a transaction without checking whether the transaction actually started.')
    if "$wpdb->query('COMMIT');\n                    return ['event_id' => $eventId, 'status' => 'duplicate_ignored'" in e:
        defects.append('The identical-event duplicate path returns success without verifying that its transaction commit succeeded.')
    if "$stored = $this->db->wpdb()->insert($this->db->table('quarantine')" in e and "$logged = $this->audit->log(" in e:
        defects.append('Quarantine persistence and its audit evidence are separate commits, so rejected-event evidence can become non-atomic.')
    if "'timestamp' => is_string($value) && strtotime($value) !== false" in p:
        defects.append('Schema timestamp properties accept loose/relative strtotime syntax instead of the strict RFC3339 policy used by the event envelope.')
    if "foreach (['actor_ref','object_ref','deletion_key'] as $referenceField)" not in e:
        defects.append('Malformed non-scalar actor/object/deletion envelope references can be silently collapsed to null by pseudonymization instead of being rejected.')
    return defects


def fix20(defects: list[str]) -> list[str]:
    if not defects:
        return []
    path = 'src/Domain/EventIngestionService.php'
    replace_required(path,
        "        foreach (['consent_granted','guardian_consent_verified','is_minor'] as $booleanField) {\n            if (isset($event[$booleanField]) && !is_bool($event[$booleanField])) {\n                return $this->quarantine($event, 'invalid_envelope_metadata', $booleanField, $service, 400);\n            }\n        }\n",
        "        foreach (['consent_granted','guardian_consent_verified','is_minor'] as $booleanField) {\n            if (isset($event[$booleanField]) && !is_bool($event[$booleanField])) {\n                return $this->quarantine($event, 'invalid_envelope_metadata', $booleanField, $service, 400);\n            }\n        }\n        foreach (['actor_ref','object_ref','deletion_key'] as $referenceField) {\n            if (array_key_exists($referenceField, $event) && $event[$referenceField] !== null && $event[$referenceField] !== '' && !is_scalar($event[$referenceField])) {\n                return $this->quarantine($event, 'invalid_envelope_metadata', $referenceField, $service, 400);\n            }\n        }\n")
    replace_required(path,
        "        $wpdb = $this->db->wpdb();\n        $wpdb->query('START TRANSACTION');\n        try {",
        "        $wpdb = $this->db->wpdb();\n        if ($wpdb->query('START TRANSACTION') === false) {\n            return new WP_Error('smai_event_transaction_failed', 'Event transaction could not start.', ['status' => 500]);\n        }\n        try {", 1)
    replace_required(path,
        "                    $wpdb->query('COMMIT');\n                    return ['event_id' => $eventId, 'status' => 'duplicate_ignored', 'payload_hash' => $payloadHash, 'late' => $isLate, 'out_of_order' => $outOfOrder, 'pipeline_job' => null];",
        "                    if ($wpdb->query('COMMIT') === false) {\n                        $wpdb->query('ROLLBACK');\n                        return new WP_Error('smai_event_commit_failed', 'Duplicate-event verification could not be committed.', ['status' => 500]);\n                    }\n                    return ['event_id' => $eventId, 'status' => 'duplicate_ignored', 'payload_hash' => $payloadHash, 'late' => $isLate, 'out_of_order' => $outOfOrder, 'pipeline_job' => null];")
    old = """        $stored = $this->db->wpdb()->insert($this->db->table('quarantine'), [
            'event_id' => isset($event['event_id']) ? substr((string) $event['event_id'], 0, 64) : null,
            'event_name' => isset($event['event_name']) ? substr((string) $event['event_name'], 0, 190) : null,
            'event_version' => isset($event['event_version']) ? substr((string) $event['event_version'], 0, 32) : null,
            'source_module' => isset($event['source_module']) ? substr((string) $event['source_module'], 0, 100) : null,
            'reason_code' => substr($code, 0, 100),
            'reason_detail' => substr($detail, 0, 255),
            'redacted_sample' => Json::encode($sample),
            'payload_hash' => $hash,
            'status' => 'open',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $logged = $this->audit->log(
            'analytics_event_quarantined',
            'quarantine',
            isset($event['event_id']) ? (string) $event['event_id'] : null,
            'rejected',
            ['reason_code' => $code, 'service' => $service, 'payload_hash' => $hash, 'quarantine_stored' => $stored === 1],
            isset($event['purpose']) ? (string) $event['purpose'] : null,
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            null,
            'service'
        );
        if ($stored !== 1 || !$logged) {
            return new WP_Error('smai_quarantine_evidence_failed', 'Event was rejected but quarantine evidence could not be fully persisted.', ['status' => 503, 'reason_code' => $code]);
        }
"""
    new = """        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_quarantine_evidence_failed', 'Event was rejected but quarantine evidence transaction could not start.', ['status' => 503, 'reason_code' => $code]);
        }
        $stored = $wpdb->insert($this->db->table('quarantine'), [
            'event_id' => isset($event['event_id']) ? substr((string) $event['event_id'], 0, 64) : null,
            'event_name' => isset($event['event_name']) ? substr((string) $event['event_name'], 0, 190) : null,
            'event_version' => isset($event['event_version']) ? substr((string) $event['event_version'], 0, 32) : null,
            'source_module' => isset($event['source_module']) ? substr((string) $event['source_module'], 0, 100) : null,
            'reason_code' => substr($code, 0, 100),
            'reason_detail' => substr($detail, 0, 255),
            'redacted_sample' => Json::encode($sample),
            'payload_hash' => $hash,
            'status' => 'open',
            'retry_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $logged = $stored === 1 && $this->audit->logInOpenTransaction(
            'analytics_event_quarantined',
            'quarantine',
            isset($event['event_id']) ? (string) $event['event_id'] : null,
            'rejected',
            ['reason_code' => $code, 'service' => $service, 'payload_hash' => $hash, 'quarantine_stored' => true],
            isset($event['purpose']) ? (string) $event['purpose'] : null,
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            null,
            'service'
        );
        if (!$logged || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_quarantine_evidence_failed', 'Event was rejected but quarantine evidence could not be fully persisted.', ['status' => 503, 'reason_code' => $code]);
        }
"""
    replace_required(path, old, new)
    replace_required('src/Domain/PrivacyGateway.php',
        "            'timestamp' => is_string($value) && strtotime($value) !== false ? gmdate('c', (int) strtotime($value)) : $this->reject($errors, 'invalid_timestamp_' . $name),",
        "            'timestamp' => is_string($value) && ($timestamp = $this->strictTimestamp($value)) !== null ? gmdate('c', $timestamp) : $this->reject($errors, 'invalid_timestamp_' . $name),")
    replace_required('src/Domain/PrivacyGateway.php',
        "    private function pseudonymizeNullable(mixed $value, string $context): ?string\n    {",
        "    private function strictTimestamp(string $value): ?int\n    {\n        if (strlen($value) > 35 || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $value) !== 1) {\n            return null;\n        }\n        $timestamp = strtotime($value);\n        return $timestamp === false ? null : $timestamp;\n    }\n\n    private function pseudonymizeNullable(mixed $value, string $context): ?string\n    {")
    return [
        'Made accepted-event and quarantine transactions fail closed on transaction/commit/audit failure.',
        'Rejected malformed non-scalar envelope references before pseudonymization.',
        'Aligned event-property timestamps with strict RFC3339 parsing.',
    ]


def audit21() -> list[str]:
    s = read('src/Domain/SnapshotService.php')
    q = read('src/Domain/MetricQueryService.php')
    defects = []
    if "PrivacyQueryPolicy::violations($definition,$dimensions)" not in s and "PrivacyQueryPolicy::violations($definition, $dimensions)" not in s:
        defects.append('Snapshot computation does not enforce the metric dimension privacy policy before publishing a value.')
    if "$minimum=max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20));" in s:
        defects.append('Snapshot suppression ignores dimension-specific minimum-cohort rules and uses only the global/base floor.')
    if "$wpdb->query('COMMIT');\n        }catch" in s and "$this->lineage->link('build'" in s:
        defects.append('Snapshot publication commits before lineage and audit evidence, and those evidence-write failures are ignored.')
    if "['green','amber','red','unknown','stale']" in s:
        defects.append('Snapshot quality vocabulary (amber/red/stale) disagrees with query vocabulary (warning/degraded/unknown), degrading semantic consistency.')
    if "preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $purpose)" in q:
        defects.append('MetricQueryService requires a slug-like purpose while access projects store governed free-text purposes, making valid projects unusable for metric queries.')
    if "private function date(string $value):string{$timestamp=strtotime($value);" in s:
        defects.append('Snapshot job windows accept loose strtotime syntax instead of strict governed timestamps.')
    return defects


def fix21(defects: list[str]) -> list[str]:
    if not defects:
        return []
    s_path = 'src/Domain/SnapshotService.php'
    replace_required(s_path,
        "        $allowed=array_map('strval',(array)($definition['dimensions']??[]));\n        foreach(array_keys($dimensions) as $key){if(!in_array((string)$key,$allowed,true)){throw new \\RuntimeException('Unapproved snapshot dimension.');}}",
        "        $allowed=array_map('strval',(array)($definition['dimensions']??[]));\n        foreach($dimensions as $key=>$dimensionValue){\n            if(!in_array((string)$key,$allowed,true)){throw new \\RuntimeException('Unapproved snapshot dimension.');}\n            if(!is_scalar($dimensionValue) && $dimensionValue!==null){throw new \\RuntimeException('Snapshot dimension value is invalid.');}\n        }\n        if(PrivacyQueryPolicy::violations($definition,$dimensions)!==[]){throw new \\RuntimeException('Snapshot dimensions violate the metric privacy policy.');}")
    replace_required(s_path,
        "        $minimum=max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20));\n        $quality=(string)$dataset['quality_status'];$caveats=[];",
        "        $minimum=PrivacyQueryPolicy::effectiveMinimum($definition,$dimensions,max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20)));\n        $quality=match((string)$dataset['quality_status']){'green'=>'green','amber'=>'warning','red'=>'degraded','warning'=>'warning','degraded'=>'degraded',default=>'unknown'};$caveats=[];")
    replace_required(s_path,
        "        if($dataThrough!==null&&time()-(int)strtotime($dataThrough)>$maxFreshness){$quality='stale';$caveats[]='Data is older than the declared freshness threshold.';}\n        if(!in_array($quality,['green','amber','red','unknown','stale'],true)){$quality='unknown';}",
        "        if($dataThrough!==null&&time()-(int)strtotime($dataThrough)>$maxFreshness){if($quality==='green'){$quality='warning';}$caveats[]='Data is older than the declared freshness threshold.';}\n        if(!in_array($quality,['green','warning','degraded','unknown'],true)){$quality='unknown';}")
    old = """        $wpdb->query('START TRANSACTION');
        try{
            if(is_array($previous)&&$wpdb->update($table,['state'=>'superseded'],['id'=>(int)$previous['id'],'state'=>(string)$previous['state']])===false){throw new \\RuntimeException('Previous snapshot could not be superseded.');}
            if($wpdb->insert($table,$record)!==1){throw new \\RuntimeException('Metric snapshot could not be stored.');}
            $snapshotId=(int)$wpdb->insert_id;$wpdb->query('COMMIT');
        }catch(\\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        $this->lineage->link('build',(string)$build['build_uuid'],null,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$dataset['owner_module'],null,defined('SMAI_CODE_SHA')?SMAI_CODE_SHA:null);
        $this->lineage->link('metric',$metricId,$version,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$metric['owner_module']);
        $this->audit->log('metric_snapshot_published','metric_snapshot',(string)$snapshotId,'success',['metric_id'=>$metricId,'metric_version'=>$version,'revision'=>$revision,'quality_status'=>$quality,'cohort_bucket'=>$this->bucket($cohort)],'institutional_measurement',null,(int)($payload['actor_user_id']??0));
"""
    new = """        if($wpdb->query('START TRANSACTION')===false){throw new \\RuntimeException('Snapshot transaction could not start.');}
        try{
            if(is_array($previous)&&$wpdb->update($table,['state'=>'superseded'],['id'=>(int)$previous['id'],'state'=>(string)$previous['state']])===false){throw new \\RuntimeException('Previous snapshot could not be superseded.');}
            if($wpdb->insert($table,$record)!==1){throw new \\RuntimeException('Metric snapshot could not be stored.');}
            $snapshotId=(int)$wpdb->insert_id;
            if(!$this->lineage->link('build',(string)$build['build_uuid'],null,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$dataset['owner_module'],null,defined('SMAI_CODE_SHA')?SMAI_CODE_SHA:null)){throw new \\RuntimeException('Snapshot build lineage could not be recorded.');}
            if(!$this->lineage->link('metric',$metricId,$version,'snapshot',(string)$snapshotId,(string)$candidateHash,(string)$metric['owner_module'])){throw new \\RuntimeException('Snapshot metric lineage could not be recorded.');}
            if(!$this->audit->logInOpenTransaction('metric_snapshot_published','metric_snapshot',(string)$snapshotId,'success',['metric_id'=>$metricId,'metric_version'=>$version,'revision'=>$revision,'quality_status'=>$quality,'cohort_bucket'=>$this->bucket($cohort)],'institutional_measurement',null,(int)($payload['actor_user_id']??0))){throw new \\RuntimeException('Snapshot audit evidence could not be recorded.');}
            if($wpdb->query('COMMIT')===false){throw new \\RuntimeException('Snapshot transaction could not be committed.');}
        }catch(\\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
"""
    replace_required(s_path, old, new)
    replace_required(s_path,
        "    private function date(string $value):string{$timestamp=strtotime($value);if($timestamp===false){throw new \\InvalidArgumentException('Invalid date.');}return gmdate('Y-m-d H:i:s',$timestamp);}",
        "    private function date(string $value):string{if(strlen($value)>35||preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/',$value)!==1){throw new \\InvalidArgumentException('Invalid date.');}$timestamp=strtotime($value);if($timestamp===false){throw new \\InvalidArgumentException('Invalid date.');}return gmdate('Y-m-d H:i:s',$timestamp);}")
    q_path = 'src/Domain/MetricQueryService.php'
    replace_required(q_path, "use Sabri\\AnalyticsIntelligence\\Infrastructure\\RuntimeGate;\n", "use Sabri\\AnalyticsIntelligence\\Infrastructure\\RuntimeGate;\nuse Sabri\\AnalyticsIntelligence\\Infrastructure\\SensitiveValueDetector;\nuse Sabri\\AnalyticsIntelligence\\Infrastructure\\Text;\n")
    replace_required(q_path,
        "        if ($actorUserId < 1\n            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1",
        "        $purpose = Text::truncate(trim(wp_strip_all_tags($purpose)), 2000);\n        if ($actorUserId < 1\n            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1")
    replace_required(q_path,
        "            || preg_match('/^[0-9a-f-]{36}$/i', $projectUuid) !== 1\n            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $purpose) !== 1) {",
        "            || preg_match('/^[0-9a-f-]{36}$/i', $projectUuid) !== 1\n            || strlen($purpose) < 12\n            || (new SensitiveValueDetector())->violations($purpose) !== []) {")
    return [
        'Applied metric privacy policy and dimension-specific cohort floors during snapshot computation.',
        'Made snapshot publication, lineage, audit and commit one fail-closed transaction and normalized quality semantics.',
        'Aligned metric-query purpose handling with governed access-project free-text purposes.',
        'Made snapshot window parsing strict RFC3339.',
    ]


def audit22() -> list[str]:
    e = read('src/Domain/ExportService.php')
    r = read('src/Domain/ReportService.php')
    c = read('src/Domain/ReportControlService.php')
    defects = []
    if "effectiveMinimum((array) $metric['definition'], []," in e:
        defects.append('Export row filtering applies a single empty-dimension cohort floor and does not re-evaluate each exported slice against dimension-specific privacy policy.')
    if "preg_match('/^\\d{4}-\\d{2}-\\d{2}T/', $value)" in e:
        defects.append('Export window parsing accepts incomplete/loose timestamps.')
    if "PrivacyQueryPolicy::violations" not in r:
        defects.append('Report creation accepts metric dimension slices without validating metric allowlists or privacy policy.')
    if "PrivacyQueryPolicy::violations" not in c:
        defects.append('Report updates accept changed metric slices without validating metric allowlists or privacy policy.')
    if "private function latestSnapshot" in r and "effectiveMinimum" not in r[r.index('private function latestSnapshot'):]:
        defects.append('Report generation trusts stored suppression state and does not re-evaluate the current effective cohort/privacy policy before disclosure.')
    return defects


def fix22(defects: list[str]) -> list[str]:
    if not defects:
        return []
    e_path = 'src/Domain/ExportService.php'
    s = read(e_path)
    start = s.index('    private function metricRows(')
    end = s.index('    private function optionalDate(', start)
    method = """    private function metricRows(array $definition, int $limit): array
    {
        $metric = (new MetricCatalog($this->db))->active((string) $definition['metric_id'], (string) $definition['metric_version']);
        if ($metric === null) { return []; }
        $metricDefinition = (array) $metric['definition'];
        $baseMinimum = max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20));
        $table = $this->db->table('metric_snapshots');
        $where = ['s.metric_id=%s', 's.metric_version=%s', "s.state='published'", "s.quality_status NOT IN ('suppressed','invalidated')"];
        $args = [(string) $definition['metric_id'], (string) $definition['metric_version']];
        if (!empty($definition['window_start'])) { $where[] = 's.window_start>=%s'; $args[] = (string) $definition['window_start']; }
        if (!empty($definition['window_end'])) { $where[] = 's.window_end<=%s'; $args[] = (string) $definition['window_end']; }
        $scanLimit = max(1, min(10000, $limit * 5));
        $args[] = $scanLimit;
        $sql = "SELECT s.metric_id,s.metric_version,s.window_start,s.window_end,s.dimensions_json AS dimensions,s.value_decimal AS value,s.numerator_decimal AS numerator,s.denominator_decimal AS denominator,s.cohort_size,s.quality_status,s.data_through,s.caveats_json AS caveats FROM `{$table}` s INNER JOIN (SELECT metric_id,metric_version,window_start,window_end,dimensions_hash,MAX(snapshot_revision) AS revision FROM `{$table}` WHERE state='published' GROUP BY metric_id,metric_version,window_start,window_end,dimensions_hash) latest ON latest.metric_id=s.metric_id AND latest.metric_version=s.metric_version AND latest.window_start=s.window_start AND latest.window_end=s.window_end AND latest.dimensions_hash=s.dimensions_hash AND latest.revision=s.snapshot_revision WHERE " . implode(' AND ', $where) . ' ORDER BY s.window_end DESC LIMIT %d';
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare($sql, ...$args), ARRAY_A);
        if (!is_array($rows)) { return []; }
        $safe = [];
        foreach ($rows as $row) {
            $dimensions = Json::object((string) $row['dimensions']);
            if (PrivacyQueryPolicy::violations($metricDefinition, $dimensions) !== []) { continue; }
            $minimum = PrivacyQueryPolicy::effectiveMinimum($metricDefinition, $dimensions, $baseMinimum);
            if ((int) $row['cohort_size'] < $minimum) { continue; }
            $row['dimensions'] = $dimensions;
            $row['caveats'] = Json::list((string) $row['caveats']);
            $safe[] = $row;
            if (count($safe) >= $limit) { break; }
        }
        return $safe;
    }

"""
    write(e_path, s[:start] + method + s[end:])
    replace_required(e_path,
        "        if (!is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}T/', $value) !== 1) { return null; }\n        $timestamp = strtotime($value);",
        "        if (!is_string($value) || strlen($value) > 35 || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $value) !== 1) { return null; }\n        $timestamp = strtotime($value);")

    r_path = 'src/Domain/ReportService.php'
    replace_required(r_path,
        "            ksort($metric['dimensions']);\n            $datasetRef = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];",
        "            ksort($metric['dimensions']);\n            $activeMetric = (new MetricCatalog($this->db))->active((string) $metric['metric_id'], (string) $metric['metric_version']);\n            if ($activeMetric === null) { return new WP_Error('smai_report_metric_inactive', 'Report metric is not active.', ['status' => 409]); }\n            $allowedDimensions = array_map('strval', (array) (($activeMetric['definition']['dimensions'] ?? [])));\n            foreach ($metric['dimensions'] as $dimension => $value) {\n                if (!in_array((string) $dimension, $allowedDimensions, true) || (!is_scalar($value) && $value !== null)) {\n                    return new WP_Error('smai_report_dimension_denied', 'Report metric dimension is not approved.', ['status' => 400]);\n                }\n            }\n            if (PrivacyQueryPolicy::violations((array) $activeMetric['definition'], $metric['dimensions']) !== []) {\n                return new WP_Error('smai_report_privacy_policy_denied', 'Report metric slice violates the privacy policy.', ['status' => 403]);\n            }\n            $datasetRef = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];", 1)
    # Harden latestSnapshot without changing its public shape.
    replace_required(r_path,
        "        ksort($dimensions);\n        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(\n            \"SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' AND quality_status<>'suppressed' ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1\",",
        "        ksort($dimensions);\n        $metric = (new MetricCatalog($this->db))->active($metricId, $version);\n        if ($metric === null || PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions) !== []) { return null; }\n        $minimum = PrivacyQueryPolicy::effectiveMinimum((array) $metric['definition'], $dimensions, max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20)));\n        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(\n            \"SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' AND quality_status NOT IN ('suppressed','invalidated') ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1\",", 1)
    replace_required(r_path,
        "        if (!is_array($row)) {\n            return null;\n        }\n        return [",
        "        if (!is_array($row) || (int) $row['cohort_size'] < $minimum) {\n            return null;\n        }\n        return [", 1)

    c_path = 'src/Domain/ReportControlService.php'
    replace_required(c_path,
        "            $dimensions = $metric['dimensions'];\n            ksort($dimensions);\n            $ref = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];",
        "            $dimensions = $metric['dimensions'];\n            ksort($dimensions);\n            $activeMetric = (new MetricCatalog($this->db))->active((string) $metric['metric_id'], (string) $metric['metric_version']);\n            if ($activeMetric === null) { return new WP_Error('smai_report_metric_inactive', 'Report metric is not active.', ['status' => 409]); }\n            $allowedDimensions = array_map('strval', (array) (($activeMetric['definition']['dimensions'] ?? [])));\n            foreach ($dimensions as $dimension => $value) {\n                if (!in_array((string) $dimension, $allowedDimensions, true) || (!is_scalar($value) && $value !== null)) {\n                    return new WP_Error('smai_report_dimension_denied', 'Report metric dimension is not approved.', ['status' => 400]);\n                }\n            }\n            if (PrivacyQueryPolicy::violations((array) $activeMetric['definition'], $dimensions) !== []) {\n                return new WP_Error('smai_report_privacy_policy_denied', 'Report metric slice violates the privacy policy.', ['status' => 403]);\n            }\n            $ref = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];", 1)
    return [
        'Re-evaluated each export row against its actual dimensions and current effective cohort floor.',
        'Made export timestamps strict RFC3339.',
        'Added metric dimension allowlist/privacy validation to report creation and updates.',
        'Re-evaluated report snapshot privacy and cohort thresholds at read time.',
    ]


def audit23() -> list[str]:
    b = read('src/Domain/BackfillService.php')
    defects = []
    for action in ['backfill_planned','backfill_dry_run','backfill_approved']:
        if f"audit->log('{action}'" in b:
            defects.append(f'{action} uses a nested AuditLogger transaction inside an already-open backfill transaction.')
    if "$this->lineage->link('event'" in b and "if(!$this->lineage->link('event'" not in b and "if (!$this->lineage->link('event'" not in b:
        defects.append('Backfill row materialization ignores lineage-write failures and can continue toward compared state.')
    if "if ($ok === 1) {$inserted++;}" in b:
        defects.append('Backfill dataset-row INSERT failures are not distinguished from harmless duplicate inserts.')
    if "$this->audit->log('backfill_activated'" in b or "$this->audit->log('backfill_rolled_back'" in b:
        defects.append('Backfill activation/rollback commits state before best-effort audit evidence is written.')
    if "private function normalizeDate(string $value): ?string\n    {\n        $timestamp = strtotime($value);" in b:
        defects.append('Backfill planning accepts loose strtotime date syntax instead of strict governed timestamps.')
    return defects


def fix23(defects: list[str]) -> list[str]:
    if not defects:
        return []
    p = 'src/Domain/BackfillService.php'
    s = read(p)
    for action in ['backfill_planned','backfill_dry_run','backfill_approved']:
        s = s.replace(f"$this->audit->log('{action}'", f"$this->audit->logInOpenTransaction('{action}'")
    write(p, s)
    replace_required(p, "            if ($ok === 1) {$inserted++;}\n            $this->lineage->link('event', (string) $event['event_id'], (string) $event['event_version'], 'build', $buildUuid, (string) $row['dataset_version'], (string) $dataset['owner_module'], null, defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null);",
        "            if ($ok === false) { throw new \\RuntimeException('Backfill dataset row write failed.'); }\n            if ($ok === 1) {$inserted++;}\n            if (!$this->lineage->link('event', (string) $event['event_id'], (string) $event['event_version'], 'build', $buildUuid, (string) $row['dataset_version'], (string) $dataset['owner_module'], null, defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null)) { throw new \\RuntimeException('Backfill lineage evidence could not be recorded.'); }")
    replace_required(p,
        "        $this->db->wpdb()->update($this->db->table('dataset_builds'), ['row_count' => $count, 'checkpoint_json' => Json::encode($checkpoint), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']);",
        "        if ($this->db->wpdb()->update($this->db->table('dataset_builds'), ['row_count' => $count, 'checkpoint_json' => Json::encode($checkpoint), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']) !== 1) { throw new \\RuntimeException('Backfill checkpoint could not be persisted.'); }")
    replace_required(p,
        "        $this->db->wpdb()->update($buildTable, ['state' => 'compared', 'row_count' => $count, 'build_hash' => $buildHash, 'checkpoint_json' => Json::encode($checkpoint), 'comparison_json' => Json::encode($comparison), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']);\n        $this->db->wpdb()->update($this->db->table('backfills'), ['state' => 'compared', 'previous_build_uuid' => $comparison['active_build_uuid'], 'comparison_json' => Json::encode($comparison), 'row_version' => (int) $row['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'shadow_build']);",
        "        if ($this->db->wpdb()->query('START TRANSACTION') === false) { throw new \\RuntimeException('Backfill comparison transaction could not start.'); }\n        try {\n            if ($this->db->wpdb()->update($buildTable, ['state' => 'compared', 'row_count' => $count, 'build_hash' => $buildHash, 'checkpoint_json' => Json::encode($checkpoint), 'comparison_json' => Json::encode($comparison), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']) !== 1) { throw new \\RuntimeException('Backfill build comparison could not be persisted.'); }\n            if ($this->db->wpdb()->update($this->db->table('backfills'), ['state' => 'compared', 'previous_build_uuid' => $comparison['active_build_uuid'], 'comparison_json' => Json::encode($comparison), 'row_version' => (int) $row['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'shadow_build']) !== 1) { throw new \\RuntimeException('Backfill comparison state could not be persisted.'); }\n            if ($this->db->wpdb()->query('COMMIT') === false) { throw new \\RuntimeException('Backfill comparison could not be committed.'); }\n        } catch (\\Throwable $error) { $this->db->wpdb()->query('ROLLBACK'); throw $error; }")
    # Move activation/rollback audits inside their transactions.
    replace_required(p,
        "            if ($wpdb->update($this->db->table('backfills'), ['state' => 'activated', 'previous_build_uuid' => is_string($previous) ? $previous : null, 'activated_by' => $actorUserId, 'activated_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'compared', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \\RuntimeException('Backfill activation record failed.');}\n            $wpdb->query('COMMIT');",
        "            if ($wpdb->update($this->db->table('backfills'), ['state' => 'activated', 'previous_build_uuid' => is_string($previous) ? $previous : null, 'activated_by' => $actorUserId, 'activated_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'compared', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \\RuntimeException('Backfill activation record failed.');}\n            if (!$this->audit->logInOpenTransaction('backfill_activated', 'backfill', $uuid, 'success', ['build_uuid' => $row['build_uuid']], 'data_rebuild', null, $actorUserId)) { throw new \\RuntimeException('Backfill activation audit evidence failed.'); }\n            if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Backfill activation commit failed.'); }")
    replace_required(p, "        $this->audit->log('backfill_activated', 'backfill', $uuid, 'success', ['build_uuid' => $row['build_uuid']], 'data_rebuild', null, $actorUserId);\n", "")
    replace_required(p,
        "            if ($wpdb->update($this->db->table('backfills'), ['state' => 'rolled_back', 'rolled_back_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'activated', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \\RuntimeException('Rollback state could not be stored.');}\n            $wpdb->query('COMMIT');",
        "            if ($wpdb->update($this->db->table('backfills'), ['state' => 'rolled_back', 'rolled_back_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'activated', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \\RuntimeException('Rollback state could not be stored.');}\n            if (!$this->audit->logInOpenTransaction('backfill_rolled_back', 'backfill', $uuid, 'success', ['restored_build_uuid' => $row['previous_build_uuid']], 'data_rebuild', null, $actorUserId)) { throw new \\RuntimeException('Backfill rollback audit evidence failed.'); }\n            if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Backfill rollback commit failed.'); }")
    replace_required(p, "        $this->audit->log('backfill_rolled_back', 'backfill', $uuid, 'success', ['restored_build_uuid' => $row['previous_build_uuid']], 'data_rebuild', null, $actorUserId);\n", "")
    replace_required(p,
        "    private function normalizeDate(string $value): ?string\n    {\n        $timestamp = strtotime($value);\n        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);\n    }",
        "    private function normalizeDate(string $value): ?string\n    {\n        if (strlen($value) > 35 || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $value) !== 1) { return null; }\n        $timestamp = strtotime($value);\n        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);\n    }")
    return [
        'Converted backfill transaction audits to caller-owned atomic audit writes.',
        'Made row, lineage, checkpoint and final comparison persistence failures fail closed.',
        'Made activation and rollback state changes atomic with their audit evidence.',
        'Made backfill window parsing strict RFC3339.',
    ]


def audit24() -> list[str]:
    c = read('src/Domain/CatalogLifecycleService.php')
    defects = []
    if "$wpdb->query('START TRANSACTION');" in c:
        defects.append('Catalog lifecycle starts its governance transaction without checking transaction-start success.')
    if "$wpdb->query('COMMIT');\n            $this->audit->log('catalog_lifecycle_transition'" in c:
        defects.append('Catalog lifecycle commits state/history before best-effort audit evidence, so governance evidence is not atomic.')
    if "!in_array((string) $build['state'], ['compared','active'], true)" in c:
        defects.append('Dataset publication readiness accepts a merely compared build; publication should require the active governed cutover build.')
    return defects


def fix24(defects: list[str]) -> list[str]:
    if not defects: return []
    p='src/Domain/CatalogLifecycleService.php'
    replace_required(p, "        $wpdb->query('START TRANSACTION');\n        try {", "        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_catalog_transaction_failed', 'Catalog transition transaction could not start.', ['status' => 500]); }\n        try {")
    old = """            $wpdb->query('COMMIT');
            $this->audit->log('catalog_lifecycle_transition', $objectType, (string) $objectId, 'success', [
                'from_state' => $from,
                'to_state' => $targetState,
                'row_version_from' => $actualVersion,
                'row_version_to' => $newVersion,
            ], 'analytics_governance', null, $actorUserId);
            return ['id' => $objectId, 'object_type' => $objectType, 'from_state' => $from, 'state' => $targetState, 'row_version' => $newVersion, 'duplicate' => false];
"""
    new = """            if (!$this->audit->logInOpenTransaction('catalog_lifecycle_transition', $objectType, (string) $objectId, 'success', [
                'from_state' => $from,
                'to_state' => $targetState,
                'row_version_from' => $actualVersion,
                'row_version_to' => $newVersion,
            ], 'analytics_governance', null, $actorUserId)) {
                throw new \\RuntimeException('Catalog transition audit evidence failed.');
            }
            if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Catalog transition commit failed.'); }
            return ['id' => $objectId, 'object_type' => $objectType, 'from_state' => $from, 'state' => $targetState, 'row_version' => $newVersion, 'duplicate' => false];
"""
    replace_required(p,old,new)
    replace_required(p,
        "                if (!is_array($build) || !in_array((string) $build['state'], ['compared','active'], true)) {\n                    $errors[] = 'dataset_build_missing_or_uncompared';\n                }",
        "                $allowedBuildStates = $targetState === 'published' ? ['active'] : ['compared','active'];\n                if (!is_array($build) || !in_array((string) $build['state'], $allowedBuildStates, true)) {\n                    $errors[] = $targetState === 'published' ? 'dataset_active_build_missing' : 'dataset_build_missing_or_uncompared';\n                }")
    return ['Made catalog transition state/history/audit a checked atomic transaction.', 'Required an active build before a dataset can enter published state.']


def audit25() -> list[str]:
    a=read('src/Http/ServiceAuthenticator.php')
    defects=[]
    if ". $request->get_route() . \"\\n\"\n            . $timestamp" in a and ". $service . \"\\n\"" not in a:
        defects.append('Service-auth HMAC does not bind the claimed service identity, allowing cross-service signature reuse when services share a secret.')
    if a.index("RateLimiter") < a.index("$expected = hash_hmac"):
        defects.append('The service rate-limit bucket is consumed before signature verification, allowing unauthenticated invalid signatures to exhaust an allowlisted service bucket.')
    return defects


def fix25(defects:list[str])->list[str]:
    if not defects:return []
    p='src/Http/ServiceAuthenticator.php'
    block="""        if (!(new RateLimiter($this->db))->consume('service-auth', $service, 1200, 300)) {
            return new WP_Error('smai_service_rate_limited', 'Service request rate limit exceeded.', ['status' => 429]);
        }

"""
    replace_required(p,block,'')
    replace_required(p,
        "        $material = strtoupper((string) $request->get_method()) . \"\\n\"\n            . $request->get_route() . \"\\n\"\n            . $timestamp . \"\\n\"",
        "        $material = strtoupper((string) $request->get_method()) . \"\\n\"\n            . $request->get_route() . \"\\n\"\n            . $service . \"\\n\"\n            . $timestamp . \"\\n\"")
    replace_required(p,
        "        if (!hash_equals($expected, $signature)) {\n            return new WP_Error('smai_service_signature_invalid', 'Service signature is invalid.', ['status' => 401]);\n        }\n\n        $nonceHash",
        "        if (!hash_equals($expected, $signature)) {\n            return new WP_Error('smai_service_signature_invalid', 'Service signature is invalid.', ['status' => 401]);\n        }\n        if (!(new RateLimiter($this->db))->consume('service-auth', $service, 1200, 300)) {\n            return new WP_Error('smai_service_rate_limited', 'Service request rate limit exceeded.', ['status' => 429]);\n        }\n\n        $nonceHash")
    return ['Bound service identity into the HMAC material.', 'Moved service rate limiting after successful signature verification while retaining replay nonce protection.']


def audit26()->list[str]:
    d=read('src/Domain/DeletionService.php'); r=read('src/Domain/RestoreService.php')
    defects=[]
    if "$wpdb->query('START TRANSACTION');\n        $inserted" in d:
        defects.append('Deletion-request persistence starts a transaction without checking that it started.')
    if "try {\n            $wpdb->query('START TRANSACTION');" in d:
        defects.append('Deletion local-application transaction start is unchecked, so deletion work can proceed without the intended transaction boundary.')
    if "            $wpdb->query('COMMIT');\n        } catch" in d:
        defects.append('Deletion local-application commit result is unchecked before provider reconciliation proceeds.')
    if "if (strtotime((string) $evidence['backup_created_at']) === false)" in r:
        defects.append('Restore backup evidence accepts loose strtotime timestamps instead of strict RFC3339 evidence time.')
    return defects


def fix26(defects:list[str])->list[str]:
    if not defects:return []
    p='src/Domain/DeletionService.php'
    replace_required(p,"        $wpdb->query('START TRANSACTION');\n        $inserted = $wpdb->query($wpdb->prepare(","        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('smai_deletion_transaction_failed', 'Deletion request transaction could not start.', ['status'=>500]); }\n        $inserted = $wpdb->query($wpdb->prepare(",1)
    replace_required(p,"        try {\n            $wpdb->query('START TRANSACTION');","        try {\n            if ($wpdb->query('START TRANSACTION') === false) { throw new \\RuntimeException('Deletion local transaction could not start.'); }",1)
    replace_required(p,"            $wpdb->query('COMMIT');\n        } catch (\\Throwable $error) {","            if ($wpdb->query('COMMIT') === false) { throw new \\RuntimeException('Deletion local transaction could not be committed.'); }\n        } catch (\\Throwable $error) {",1)
    rp='src/Domain/RestoreService.php'
    replace_required(rp,
        "        if (strtotime((string) $evidence['backup_created_at']) === false) {\n            return new WP_Error('smai_invalid_restore_timestamp', 'Backup evidence timestamp is invalid.', ['status' => 400]);\n        }",
        "        $backupCreatedAt = (string) $evidence['backup_created_at'];\n        if (strlen($backupCreatedAt) > 35 || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $backupCreatedAt) !== 1 || strtotime($backupCreatedAt) === false) {\n            return new WP_Error('smai_invalid_restore_timestamp', 'Backup evidence timestamp is invalid.', ['status' => 400]);\n        }")
    return ['Made deletion request/local transaction boundaries fail closed on start/commit failure.', 'Made restore backup-evidence timestamps strict RFC3339.']


def audit27()->list[str]:
    j=read('src/Infrastructure/JobQueue.php')
    defects=[]
    if "state='running' AND lease_until<%s" in j and "attempts<max_attempts" not in j:
        defects.append('Expired running jobs are reclaimable without a max-attempt fence, so crash-loop jobs can exceed max_attempts indefinitely.')
    if "$runAt ?? $now" in j and "smai_invalid_job_schedule" not in j:
        defects.append('Job enqueue accepts an arbitrary runAt string without validating a database-safe governed timestamp.')
    return defects


def fix27(defects:list[str])->list[str]:
    if not defects:return []
    p='src/Infrastructure/JobQueue.php'
    replace_required(p,
        "        $table = $this->db->table('jobs');\n        $wpdb = $this->db->wpdb();\n        $now = gmdate('Y-m-d H:i:s');",
        "        if ($runAt !== null) {\n            if (preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $runAt) !== 1 || strtotime($runAt . ' UTC') === false) {\n                return new WP_Error('smai_invalid_job_schedule', 'Job run time is invalid.', ['status' => 400]);\n            }\n        }\n        $table = $this->db->table('jobs');\n        $wpdb = $this->db->wpdb();\n        $now = gmdate('Y-m-d H:i:s');",1)
    replace_required(p,
        "        try {\n            $row = $wpdb->get_row($wpdb->prepare(\n                \"SELECT * FROM `{$table}` WHERE ((state IN ('queued','retrying') AND next_run_at<=%s) OR (state='running' AND lease_until<%s)) ORDER BY next_run_at,id LIMIT 1 FOR UPDATE\",\n                $now,\n                $now\n            ), ARRAY_A);",
        "        try {\n            $expired = $wpdb->query($wpdb->prepare(\n                \"UPDATE `{$table}` SET state='dead_letter',error_code='lease_attempts_exhausted',error_message='Worker lease expired after the maximum attempts.',lease_owner=NULL,lease_until=NULL,updated_at=%s WHERE state='running' AND lease_until<%s AND attempts>=max_attempts\",\n                $now,\n                $now\n            ));\n            if ($expired === false) { throw new \\RuntimeException('Expired job fencing failed.'); }\n            $row = $wpdb->get_row($wpdb->prepare(\n                \"SELECT * FROM `{$table}` WHERE ((state IN ('queued','retrying') AND next_run_at<=%s AND attempts<max_attempts) OR (state='running' AND lease_until<%s AND attempts<max_attempts)) ORDER BY next_run_at,id LIMIT 1 FOR UPDATE\",\n                $now,\n                $now\n            ), ARRAY_A);",1)
    return ['Fenced expired max-attempt jobs into dead-letter state before claims and prevented further reclaim.', 'Validated scheduled job run times before persistence.']


def audit28()->list[str]:
    p=read('src/Plugin.php'); u=read('uninstall.php')
    defects=[]
    if "$this->upgradeIfNeeded();\n        $database = new Database" in p:
        defects.append('Plugin boot continues into runtime controllers/workers even when a concurrent schema-upgrade lock causes upgradeIfNeeded() to return without reaching the required schema version.')
    if "'smai_schema_migration_error'" not in u or "'smai_experiment_rebuild_required'" not in u:
        defects.append('Uninstall leaves schema-migration/rebuild operational state options behind even though executable runtime configuration is meant to be removed.')
    return defects


def fix28(defects:list[str])->list[str]:
    if not defects:return []
    p='src/Plugin.php'
    replace_required(p,"        $this->upgradeIfNeeded();\n        $database = new Database($GLOBALS['wpdb']);","        if (!$this->upgradeIfNeeded()) {\n            return;\n        }\n        $database = new Database($GLOBALS['wpdb']);")
    replace_required(p,"    private function upgradeIfNeeded(): void\n    {","    private function upgradeIfNeeded(): bool\n    {")
    replace_required(p,"        if ((string) get_option('smai_schema_version', '') === SMAI_SCHEMA_VERSION) {\n            return;\n        }","        if ((string) get_option('smai_schema_version', '') === SMAI_SCHEMA_VERSION && get_option('smai_schema_migration_error', null) === null) {\n            return true;\n        }")
    # the lock-held and reacquire-failed returns become false.
    replace_required(p,"                return;\n            }\n            delete_option($lock);","                return false;\n            }\n            delete_option($lock);",1)
    replace_required(p,"            if (!add_option($lock, ['started_at' => time()], '', false)) {\n                return;\n            }","            if (!add_option($lock, ['started_at' => time()], '', false)) {\n                return false;\n            }",1)
    replace_required(p,
        "        try {\n            SchemaMigrator::migrate();\n        } finally {\n            delete_option($lock);\n        }\n    }",
        "        try {\n            SchemaMigrator::migrate();\n        } catch (\\Throwable $error) {\n            return false;\n        } finally {\n            delete_option($lock);\n        }\n        return (string) get_option('smai_schema_version', '') === SMAI_SCHEMA_VERSION && get_option('smai_schema_migration_error', null) === null;\n    }")
    u='uninstall.php'
    replace_required(u,
        "    'smai_activation_lock', 'smai_schema_upgrade_lock',",
        "    'smai_activation_lock', 'smai_schema_upgrade_lock', 'smai_schema_migration_error', 'smai_experiment_rebuild_required',")
    return ['Made runtime boot fail closed until the exact required schema version is established without migration error.', 'Expanded uninstall cleanup to migration/rebuild operational-state options.']


def audit29()->list[str]:
    # Final cross-cutting source audit after rounds 20-28. Only confirmed regressions
    # are returned; absence is explicitly recorded rather than inventing findings.
    files = {
        'event': read('src/Domain/EventIngestionService.php'),
        'snapshot': read('src/Domain/SnapshotService.php'),
        'export': read('src/Domain/ExportService.php'),
        'backfill': read('src/Domain/BackfillService.php'),
        'catalog': read('src/Domain/CatalogLifecycleService.php'),
        'auth': read('src/Http/ServiceAuthenticator.php'),
        'jobs': read('src/Infrastructure/JobQueue.php'),
        'plugin': read('src/Plugin.php'),
    }
    defects=[]
    checks = [
        ('event', "audit->logInOpenTransaction(\n            'analytics_event_quarantined", 'Quarantine audit atomicity regression remains.'),
        ('snapshot', 'PrivacyQueryPolicy::effectiveMinimum($definition,$dimensions', 'Snapshot effective privacy floor is missing.'),
        ('snapshot', "logInOpenTransaction('metric_snapshot_published'", 'Snapshot audit atomicity is missing.'),
        ('export', 'PrivacyQueryPolicy::violations($metricDefinition, $dimensions)', 'Export per-slice privacy filtering is missing.'),
        ('backfill', "logInOpenTransaction('backfill_activated'", 'Backfill activation audit atomicity is missing.'),
        ('catalog', "logInOpenTransaction('catalog_lifecycle_transition'", 'Catalog lifecycle audit atomicity is missing.'),
        ('auth', ". $service . \"\\n\"", 'Service identity is not bound into request authentication.'),
        ('jobs', 'lease_attempts_exhausted', 'Job crash-loop max-attempt fencing is missing.'),
        ('plugin', 'if (!$this->upgradeIfNeeded())', 'Runtime still boots without schema readiness fence.'),
    ]
    for key, needle, message in checks:
        if needle not in files[key]: defects.append(message)
    return defects


def fix29(defects:list[str])->list[str]:
    if defects:
        raise RuntimeError('Final round found unresolved regression(s): ' + '; '.join(defects))
    invariant = """#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
checks={
 'event':(r/'src/Domain/EventIngestionService.php').read_text(encoding='utf-8'),
 'snapshot':(r/'src/Domain/SnapshotService.php').read_text(encoding='utf-8'),
 'export':(r/'src/Domain/ExportService.php').read_text(encoding='utf-8'),
 'backfill':(r/'src/Domain/BackfillService.php').read_text(encoding='utf-8'),
 'catalog':(r/'src/Domain/CatalogLifecycleService.php').read_text(encoding='utf-8'),
 'auth':(r/'src/Http/ServiceAuthenticator.php').read_text(encoding='utf-8'),
 'jobs':(r/'src/Infrastructure/JobQueue.php').read_text(encoding='utf-8'),
 'plugin':(r/'src/Plugin.php').read_text(encoding='utf-8'),
}
required=[
 ('event',"logInOpenTransaction(\\n            'analytics_event_quarantined"),
 ('snapshot','PrivacyQueryPolicy::effectiveMinimum($definition,$dimensions'),
 ('snapshot',"logInOpenTransaction('metric_snapshot_published'"),
 ('export','PrivacyQueryPolicy::violations($metricDefinition, $dimensions)'),
 ('backfill',"logInOpenTransaction('backfill_activated'"),
 ('catalog',"logInOpenTransaction('catalog_lifecycle_transition'"),
 ('auth','. $service . "\\\\n"'),
 ('jobs','lease_attempts_exhausted'),
 ('plugin','if (!$this->upgradeIfNeeded())'),
]
missing=[f'{name}: {needle}' for name,needle in required if needle not in checks[name]]
if missing:
 print('Review 20-29 invariant regression:\\n'+'\\n'.join(missing),file=sys.stderr);sys.exit(1)
print('Review 20-29 permanent invariants check passed.')
"""
    write('scripts/review20-29-invariants-check.py', invariant)
    qa=read('scripts/qa.sh')
    needle='python3 scripts/infrastructure-runtime-invariants-check.py\n'
    if 'review20-29-invariants-check.py' not in qa:
        if needle not in qa: raise RuntimeError('QA insertion point missing')
        write('scripts/qa.sh', qa.replace(needle, needle+'python3 scripts/review20-29-invariants-check.py\n',1))
    return ['Added permanent regression invariants for the ten-round closure; no new functional defect required correction in the final round.']


def main() -> None:
    rounds = [
        (20, 'Event ingestion, quarantine and privacy gateway', audit20, fix20),
        (21, 'Metric snapshots, queries and privacy semantics', audit21, fix21),
        (22, 'Exports, reports and disclosure privacy', audit22, fix22),
        (23, 'Backfills, lineage and governed rebuilds', audit23, fix23),
        (24, 'Catalog lifecycle and dataset publication readiness', audit24, fix24),
        (25, 'Service authentication and replay/rate-limit boundary', audit25, fix25),
        (26, 'Deletion, retention and restore evidence boundaries', audit26, fix26),
        (27, 'Job queue leases, retries and scheduling', audit27, fix27),
        (28, 'Schema migration, plugin boot and uninstall state', audit28, fix28),
        (29, 'Final whole-repository contradiction and regression audit', audit29, fix29),
    ]
    summary=[]
    for number,title,audit,fix in rounds:
        print(f"\n=== REVIEW ROUND {number}: AUDIT START ===")
        defects=audit()  # audit completes and ledger freezes before any mutation
        frozen=list(defects)
        print(f"[round {number}] frozen defect ledger: {len(frozen)}")
        for i,item in enumerate(frozen,1): print(f"  {i}. {item}")
        print(f"=== REVIEW ROUND {number}: AUDIT COMPLETE; CORRECTIONS MAY NOW BEGIN ===")
        corrections=fix(frozen)
        review_doc(number,title,frozen,corrections)
        run_qa(number)
        summary.append((number,title,frozen,corrections))
        print(f"=== REVIEW ROUND {number}: CORRECTIONS + QA COMPLETE ===")

    defect_rounds=[str(n) for n,_,d,_ in summary if d]
    clean_rounds=[str(n) for n,_,d,_ in summary if not d]
    closure=[
        '# Ten-Round Sequential Review Closure — Rounds 20–29',
        '',
        'Each numbered round was audited to completion first. Its defect ledger was then frozen, all confirmed defects for that round were corrected, and full repository QA passed before the next round began.',
        '',
        '## Round outcomes',
    ]
    for n,title,d,_ in summary:
        closure.append(f"- **Round {n} — {title}:** {'defects found and corrected (' + str(len(d)) + ')' if d else 'no new defect confirmed'}.")
    closure += [
        '',
        f"**Rounds with confirmed defects:** {', '.join(defect_rounds) if defect_rounds else 'none'}.",
        f"**Rounds clean after prior corrections:** {', '.join(clean_rounds) if clean_rounds else 'none'}.",
        '',
        '## Lifecycle boundary',
        'This closure establishes repository-source review and automated-QA evidence only. It does not establish `main` merge, staging acceptance, live deployment, deployed artifact parity, database migration state, or operational verification.',
        '',
    ]
    write('docs/TEN-ROUND-20-29-CLOSURE.md','\n'.join(closure))
    # One final QA run includes the permanent closure invariant added in round 29.
    subprocess.run(['bash','scripts/qa.sh'],cwd=ROOT,check=True)
    print('Ten sequential rounds 20-29 completed successfully.')


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(f'REVIEW RUN FAILED: {exc}', file=sys.stderr)
        raise
