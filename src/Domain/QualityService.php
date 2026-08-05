<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class QualityService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $rule */
    public function register(array $rule, int $actorUserId): array|WP_Error
    {
        foreach (['rule_id','rule_version','dataset_ref','rule_type','severity','config'] as $key) {
            if (!array_key_exists($key, $rule)) {return new WP_Error('smai_invalid_quality_rule', 'Quality rule is incomplete.', ['status' => 400, 'field' => $key]);}
        }
        $types = ['freshness','completeness','uniqueness','validity','referential_integrity','distribution_drift','reconciliation'];
        if ($actorUserId < 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $rule['rule_id']) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $rule['rule_version']) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/', (string) $rule['dataset_ref']) !== 1
            || !in_array((string) $rule['rule_type'], $types, true)
            || !in_array((string) $rule['severity'], ['low','medium','high','critical'], true)
            || !is_array($rule['config']) || (new SensitiveValueDetector())->violations($rule['config']) !== []) {
            return new WP_Error('smai_invalid_quality_rule', 'Quality rule validation failed.', ['status' => 400]);
        }
        [$datasetId,$datasetVersion] = $this->splitRef((string) $rule['dataset_ref']);
        $exists = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s", $datasetId, $datasetVersion));
        if ($exists !== 1) {return new WP_Error('smai_quality_dataset_missing', 'Quality rule dataset does not exist.', ['status' => 404]);}
        $configError = $this->validateConfig((string) $rule['rule_type'], $rule['config']);
        if ($configError !== null) {return $configError;}
        $json = Json::canonical($rule['config']);
        $hash = hash('sha256', $json);
        $table = $this->db->table('quality_rules');
        $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT id,config_hash,state,row_version FROM `{$table}` WHERE rule_id=%s AND rule_version=%s", $rule['rule_id'], $rule['rule_version']), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['config_hash'], $hash)) {return ['id' => (int) $existing['id'], 'state' => (string) $existing['state'], 'row_version' => (int) $existing['row_version'], 'unchanged' => true];}
            return new WP_Error('smai_quality_rule_immutable', 'Existing quality rule version is immutable.', ['status' => 409]);
        }
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($table, [
            'rule_id' => $rule['rule_id'], 'rule_version' => $rule['rule_version'], 'dataset_ref' => $rule['dataset_ref'],
            'rule_type' => $rule['rule_type'], 'severity' => $rule['severity'], 'state' => 'draft',
            'config_json' => $json, 'config_hash' => $hash, 'owner_user_id' => $actorUserId,
            'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($ok !== 1) {return new WP_Error('smai_quality_rule_store_failed', 'Quality rule could not be stored.', ['status' => 500]);}
        return ['id' => (int) $this->db->wpdb()->insert_id, 'state' => 'draft', 'row_version' => 1, 'config_hash' => $hash];
    }

    public function activate(string $ruleId, string $version, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $table = $this->db->table('quality_rules');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE rule_id=%s AND rule_version=%s", $ruleId, $version), ARRAY_A);
        if (!is_array($row) || (string) $row['state'] !== 'draft' || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_quality_rule_stale', 'Quality rule is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $row['owner_user_id'] === $actorUserId) {return new WP_Error('smai_separation_of_duties', 'Independent quality-rule approval is required.', ['status' => 403]);}
        $updated = $this->db->wpdb()->update($table, ['state' => 'active', 'approved_by' => $actorUserId, 'row_version' => $expectedVersion + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {return new WP_Error('smai_quality_rule_conflict', 'Quality rule changed concurrently.', ['status' => 409]);}
        $this->audit->log('quality_rule_activated', 'quality_rule', $ruleId . '@' . $version, 'success', ['dataset_ref' => $row['dataset_ref']], 'data_quality', null, $actorUserId);
        return ['rule_id' => $ruleId, 'rule_version' => $version, 'state' => 'active', 'row_version' => $expectedVersion + 1];
    }

    public function enqueue(string $datasetRef, int $actorUserId, ?string $buildUuid = null): array|WP_Error
    {
        try {$this->splitRef($datasetRef);} catch (\Throwable $e) {return new WP_Error('smai_invalid_dataset_ref', 'Dataset reference is invalid.', ['status' => 400]);}
        return (new JobQueue($this->db))->enqueue('quality.run', ['dataset_ref' => $datasetRef, 'build_uuid' => $buildUuid, 'actor_user_id' => $actorUserId], 'quality|' . $datasetRef . '|' . ($buildUuid ?: 'active') . '|' . gmdate('Y-m-d-H'));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $datasetRef = (string) ($payload['dataset_ref'] ?? '');
        [$datasetId, $version] = $this->splitRef($datasetRef);
        $wpdb = $this->db->wpdb();
        $dataset = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s", $datasetId, $version), ARRAY_A);
        $requestedBuild = (string) ($payload['build_uuid'] ?? '');
        $build = $wpdb->get_row($requestedBuild !== ''
            ? $wpdb->prepare("SELECT * FROM `{$this->db->table('dataset_builds')}` WHERE build_uuid=%s AND dataset_id=%s AND dataset_version=%s", $requestedBuild, $datasetId, $version)
            : $wpdb->prepare("SELECT * FROM `{$this->db->table('dataset_builds')}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 LIMIT 1", $datasetId, $version), ARRAY_A);
        if (!is_array($dataset) || !is_array($build)) {throw new \RuntimeException('Dataset or requested build is unavailable.');}
        $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$this->db->table('quality_rules')}` WHERE dataset_ref=%s AND state='active' ORDER BY id", $datasetRef), ARRAY_A);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT source_object_ref,row_json,effective_from,row_hash,is_current FROM `{$this->db->table('dataset_rows')}` WHERE build_uuid=%s", $build['build_uuid']), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $decoded = array_map(static fn(array $row): array => Json::object((string) $row['row_json']), $rows);
        $runUuid = Uuid::v4();
        $results = [];
        $worst = 'green';
        if (!is_array($rules) || $rules === []) {
            $worst = 'unknown';
            $results[] = ['rule_id' => null, 'status' => 'unknown', 'reason' => 'no_active_quality_rules'];
        }
        foreach (is_array($rules) ? $rules : [] as $rule) {
            $config = Json::object((string) $rule['config_json']);
            [$status,$observed,$threshold,$evidence] = $this->evaluate((string) $rule['rule_type'], $config, $rows, $decoded, $datasetRef, (string) $build['build_uuid']);
            if ($status !== 'pass') {
                $worst = in_array((string) $rule['severity'], ['high','critical'], true) ? 'red' : ($worst === 'red' ? 'red' : 'amber');
                $this->upsertIssue($datasetRef, (string) $rule['rule_id'], (string) $rule['severity'], $evidence, (int) $rule['owner_user_id']);
            } else {
                $this->resolveIssue($datasetRef, (string) $rule['rule_id']);
            }
            if ($wpdb->insert($this->db->table('quality_results'), [
                'run_uuid' => $runUuid, 'rule_id' => $rule['rule_id'], 'rule_version' => $rule['rule_version'], 'dataset_ref' => $datasetRef,
                'build_uuid' => $build['build_uuid'], 'status' => $status, 'observed_decimal' => $observed, 'threshold_decimal' => $threshold,
                'evidence_json' => Json::encode($evidence), 'created_at' => $this->db->now(),
            ]) !== 1) {throw new \RuntimeException('Quality result could not be stored.');}
            $results[] = ['rule_id' => $rule['rule_id'], 'status' => $status, 'severity' => $rule['severity'], 'observed' => $observed, 'threshold' => $threshold];
        }
        if ((int) $build['is_active'] === 1) {$wpdb->update($this->db->table('datasets'), ['quality_status' => $worst, 'updated_at' => $this->db->now()], ['id' => (int) $dataset['id']]);}
        $this->audit->log('dataset_quality_run', 'dataset', $datasetRef, $worst, ['run_uuid' => $runUuid, 'build_uuid' => $build['build_uuid'], 'rules' => count($results)], 'data_quality', null, (int) ($payload['actor_user_id'] ?? 0));
        return ['run_uuid' => $runUuid, 'dataset_ref' => $datasetRef, 'build_uuid' => $build['build_uuid'], 'quality_status' => $worst, 'results' => $results];
    }

    /** @param array<string,mixed> $config @param array<int,array<string,mixed>> $storedRows @param array<int,array<string,mixed>> $rows */
    private function evaluate(string $type, array $config, array $storedRows, array $rows, string $datasetRef, string $buildUuid): array
    {
        $threshold = isset($config['threshold']) ? (float) $config['threshold'] : null;
        if ($type === 'freshness') {
            $maxAge = max(1, (int) ($config['max_age_seconds'] ?? 86400)); $latest = 0;
            foreach ($storedRows as $row) {$latest = max($latest, (int) strtotime((string) ($row['effective_from'] ?? '')));}
            $age = $latest > 0 ? max(0, time() - $latest) : PHP_INT_MAX;
            return [$age <= $maxAge ? 'pass' : 'fail', (float) $age, (float) $maxAge, ['latest_at' => $latest ? gmdate('c', $latest) : null]];
        }
        if ($type === 'completeness') {
            $field=(string)($config['field']??''); $complete=0;
            foreach($rows as $row){if(array_key_exists($field,$row)&&$row[$field]!==null&&$row[$field]!==''){$complete++;}}
            $ratio=$rows===[]?0.0:$complete/count($rows); $required=$threshold??1.0;
            return [$ratio >= $required?'pass':'fail',$ratio,$required,['field'=>$field,'complete'=>$complete,'total'=>count($rows)]];
        }
        if ($type === 'uniqueness') {
            $field=(string)($config['field']??''); $seen=[];$duplicates=0;
            foreach($rows as $row){$key=Json::canonical($row[$field]??null);if(isset($seen[$key])){$duplicates++;}$seen[$key]=true;}
            $max=(int)($config['max_duplicates']??0);return[$duplicates<=$max?'pass':'fail',(float)$duplicates,(float)$max,['field'=>$field,'total'=>count($rows)]];
        }
        if ($type === 'validity') {
            $field=(string)($config['field']??'');$allowed=is_array($config['allowed']??null)?$config['allowed']:[];$invalid=0;
            foreach($rows as $row){if(!in_array($row[$field]??null,$allowed,true)){$invalid++;}}
            $max=(int)($config['max_invalid']??0);return[$invalid<=$max?'pass':'fail',(float)$invalid,(float)$max,['field'=>$field,'total'=>count($rows)]];
        }
        if ($type === 'referential_integrity') {
            $field=(string)($config['field']??'');$targetRef=(string)($config['target_dataset_ref']??'');$targetField=(string)($config['target_field']??'');
            [$tid,$tver]=$this->splitRef($targetRef);$targetBuild=$this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT build_uuid FROM `{$this->db->table('dataset_builds')}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1",$tid,$tver));
            if(!is_string($targetBuild)){return['fail',1.0,0.0,['reason'=>'target_build_missing']];}
            $targetRows=$this->db->wpdb()->get_col($this->db->wpdb()->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(row_json,%s)) FROM `{$this->db->table('dataset_rows')}` WHERE build_uuid=%s AND is_current=1",'$.' . $targetField,$targetBuild));
            $set=array_fill_keys(array_map('strval',is_array($targetRows)?$targetRows:[]),true);$missing=0;
            foreach($rows as $row){$v=(string)($row[$field]??'');if($v!==''&&!isset($set[$v])){$missing++;}}
            $max=(int)($config['max_missing']??0);return[$missing<=$max?'pass':'fail',(float)$missing,(float)$max,['field'=>$field,'target'=>$targetRef,'target_field'=>$targetField]];
        }
        if ($type === 'distribution_drift') {
            $field=(string)($config['field']??'');$baseline=is_array($config['baseline']??null)?$config['baseline']:[];$counts=[];
            foreach($rows as $row){$k=(string)($row[$field]??'__null__');$counts[$k]=($counts[$k]??0)+1;}
            $total=max(1,count($rows));$maxDelta=0.0;
            foreach($baseline as $k=>$ratio){$observed=($counts[(string)$k]??0)/$total;$maxDelta=max($maxDelta,abs($observed-(float)$ratio));}
            $limit=(float)($config['max_absolute_delta']??0.1);return[$maxDelta<=$limit?'pass':'fail',$maxDelta,$limit,['field'=>$field,'observed_counts'=>$counts]];
        }
        $expected=(int)($config['expected_count']??count($rows));$tolerance=max(0,(int)($config['tolerance']??0));$difference=abs(count($rows)-$expected);
        return[$difference<=$tolerance?'pass':'fail',(float)count($rows),(float)$expected,['difference'=>$difference,'tolerance'=>$tolerance,'dataset_ref'=>$datasetRef,'build_uuid'=>$buildUuid]];
    }

    /** @param array<string,mixed> $config */
    private function validateConfig(string $type, array $config): ?WP_Error
    {
        if (in_array($type,['completeness','uniqueness','validity','distribution_drift'],true) && preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)($config['field']??''))!==1) {return new WP_Error('smai_quality_field_required','Quality rule field is invalid.',['status'=>400]);}
        if ($type==='referential_integrity' && (preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)($config['field']??''))!==1 || preg_match('/^[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/',(string)($config['target_dataset_ref']??''))!==1 || preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)($config['target_field']??''))!==1)) {return new WP_Error('smai_quality_reference_invalid','Referential-integrity rule is invalid.',['status'=>400]);}
        return null;
    }

    /** @param array<string,mixed> $evidence */
    private function upsertIssue(string $datasetRef,string $ruleId,string $severity,array $evidence,int $owner):void
    {
        $table=$this->db->table('quality_issues');$existing=$this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT id FROM `{$table}` WHERE dataset_ref=%s AND rule_id=%s AND state='open' LIMIT 1",$datasetRef,$ruleId));
        if($existing){$this->db->wpdb()->update($table,['severity'=>$severity,'evidence_json'=>Json::encode($evidence),'updated_at'=>$this->db->now()],['id'=>(int)$existing]);return;}
        $this->db->wpdb()->insert($table,['issue_uuid'=>Uuid::v4(),'dataset_ref'=>$datasetRef,'rule_id'=>$ruleId,'severity'=>$severity,'state'=>'open','summary'=>'Quality rule failed: '.$ruleId,'evidence_json'=>Json::encode($evidence),'owner_user_id'=>$owner?:null,'detected_at'=>$this->db->now(),'updated_at'=>$this->db->now()]);
    }

    private function resolveIssue(string $datasetRef,string $ruleId):void
    {
        $this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$this->db->table('quality_issues')}` SET state='resolved',resolved_at=%s,updated_at=%s WHERE dataset_ref=%s AND rule_id=%s AND state='open'",$this->db->now(),$this->db->now(),$datasetRef,$ruleId));
    }

    /** @return array{0:string,1:string} */
    private function splitRef(string $ref): array
    {
        $parts=explode('@',$ref,2);if(count($parts)!==2){throw new \InvalidArgumentException('Dataset reference must be id@version.');}return[$parts[0],$parts[1]];
    }
}
