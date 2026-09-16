<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class FutureArtifactStore
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function prepareInput(string $featureId, array $input, bool $dryRun): array|WP_Error
    {
        if ($featureId !== 'CF05-FUT-025' || $dryRun) return $input;
        $project = trim((string)($input['project_uuid'] ?? ''));
        $metric = trim((string)($input['metric_id'] ?? ''));
        $request = $this->number($input['request'] ?? null);
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $project) !== 1 || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metric) !== 1 || $request === null || $request < 0) {
            return new WP_Error('smai_future_privacy_budget_identity_required', 'Active privacy-budget execution requires a valid project, metric and non-negative request.', ['status'=>400]);
        }
        $budgetKey = hash('sha256', strtolower($project) . '|' . strtolower($metric));
        $table = $this->db->table('privacy_budgets');
        $wpdb = $this->db->wpdb();
        $row = $wpdb->get_row($wpdb->prepare("SELECT epsilon_allocated,epsilon_spent,row_version FROM `{$table}` WHERE budget_key=%s FOR UPDATE", $budgetKey), ARRAY_A);
        if (is_array($row)) {
            $input['allocated'] = (float)$row['epsilon_allocated'];
            $input['spent'] = (float)$row['epsilon_spent'];
            $input['_budget_row_version'] = (int)$row['row_version'];
        } else {
            $allocated = $this->number($input['allocated'] ?? null);
            if ($allocated === null || $allocated <= 0) return new WP_Error('smai_future_privacy_budget_allocation_required', 'A positive initial privacy-budget allocation is required.', ['status'=>400]);
            $input['allocated'] = $allocated;
            $input['spent'] = 0.0;
            $input['_budget_row_version'] = 0;
        }
        $input['project_uuid'] = strtolower($project);
        $input['metric_id'] = strtolower($metric);
        $input['_budget_key'] = $budgetKey;
        return $input;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $result
     * @return array<string,mixed>|WP_Error
     */
    public function persist(string $featureId, array $input, array $result, int $actorUserId, bool $dryRun): array|WP_Error
    {
        if ($dryRun) return [];
        return match ($featureId) {
            'CF05-FUT-021' => $this->persistScenario($input, $result, $actorUserId),
            'CF05-FUT-025' => $this->persistPrivacyBudget($input, $result),
            'CF05-FUT-029' => $this->persistResearchWorkspace($input, $actorUserId),
            'CF05-FUT-038' => $this->persistTransparencyRecord($input),
            default => [],
        };
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $result @return array<string,mixed>|WP_Error */
    private function persistScenario(array $input, array $result, int $actorUserId): array|WP_Error
    {
        $name = Text::truncate(trim((string)($input['name'] ?? 'Scenario model')), 190);
        if ($name === '') return new WP_Error('smai_future_scenario_name_required', 'Scenario name is required for active persistence.', ['status'=>400]);
        $uuid = Uuid::v4(); $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('scenario_models'), [
            'scenario_uuid'=>$uuid,'feature_id'=>'CF05-FUT-021','name'=>$name,
            'definition_json'=>Json::canonical($this->clean($input)),'result_json'=>Json::canonical($this->clean($result)),
            'owner_user_id'=>$actorUserId,'row_version'=>1,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return $ok === 1 ? ['scenario_uuid'=>$uuid] : new WP_Error('smai_future_scenario_store_failed', 'Scenario evidence could not be persisted.', ['status'=>500]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $result @return array<string,mixed>|WP_Error */
    private function persistPrivacyBudget(array $input, array $result): array|WP_Error
    {
        $key = (string)($input['_budget_key'] ?? ''); $project=(string)($input['project_uuid']??''); $metric=(string)($input['metric_id']??'');
        $allocated=$this->number($input['allocated']??null); $spent=$this->number($input['spent']??null); $request=$this->number($input['request']??null); $rowVersion=max(0,(int)($input['_budget_row_version']??0));
        if (preg_match('/^[a-f0-9]{64}$/',$key)!==1 || $allocated===null || $spent===null || $request===null) return new WP_Error('smai_future_privacy_budget_state_invalid','Privacy budget state is invalid.',['status'=>500]);
        $allowed=($result['allowed']??false)===true; $newSpent=$allowed ? $spent+$request : $spent; $table=$this->db->table('privacy_budgets'); $wpdb=$this->db->wpdb(); $now=$this->db->now();
        if ($rowVersion === 0) {
            $ok=$wpdb->insert($table,['budget_key'=>$key,'project_uuid'=>$project,'metric_id'=>$metric,'epsilon_allocated'=>$allocated,'epsilon_spent'=>$newSpent,'row_version'=>1,'updated_at'=>$now]);
            if($ok!==1)return new WP_Error('smai_future_privacy_budget_store_failed','Privacy budget ledger could not be created.',['status'=>409]);
            return ['budget_key'=>$key,'budget_row_version'=>1,'epsilon_spent'=>$newSpent];
        }
        $ok=$wpdb->update($table,['epsilon_spent'=>$newSpent,'row_version'=>$rowVersion+1,'updated_at'=>$now],['budget_key'=>$key,'row_version'=>$rowVersion]);
        if($ok!==1)return new WP_Error('smai_future_privacy_budget_conflict','Privacy budget ledger changed concurrently.',['status'=>409]);
        return ['budget_key'=>$key,'budget_row_version'=>$rowVersion+1,'epsilon_spent'=>$newSpent];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    private function persistResearchWorkspace(array $input, int $actorUserId): array|WP_Error
    {
        $title=Text::truncate(trim((string)($input['title']??'')),190);$purpose=Text::truncate(trim((string)($input['purpose']??'')),500);$datasets=$input['approved_aggregate_datasets']??[];
        if($title===''||$purpose===''||!is_array($datasets)||$datasets===[])return new WP_Error('smai_future_research_metadata_required','Research workspace requires title, purpose and approved aggregate datasets.',['status'=>400]);
        $uuid=Uuid::v4();$now=$this->db->now();$ok=$this->db->wpdb()->insert($this->db->table('research_workspaces'),[
            'workspace_uuid'=>$uuid,'title'=>$title,'purpose'=>$purpose,'datasets_json'=>Json::canonical(array_values(array_map('strval',$datasets))),'state'=>'draft','owner_user_id'=>$actorUserId,'approved_by'=>null,'expires_at'=>null,'row_version'=>1,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return $ok===1?['workspace_uuid'=>$uuid]:new WP_Error('smai_future_research_store_failed','Research workspace metadata could not be persisted.',['status'=>500]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    private function persistTransparencyRecord(array $input): array|WP_Error
    {
        $purpose=Text::truncate(trim((string)($input['purpose']??'')),500);$metrics=$input['metric_ids']??[];$classes=$input['data_classes']??[];$retention=Text::truncate(trim((string)($input['retention_summary']??'')),500);
        if($purpose===''||!is_array($metrics)||$metrics===[]||!is_array($classes)||$classes===[]||$retention==='')return new WP_Error('smai_future_transparency_metadata_required','Transparency record requires purpose, metrics, data classes and retention summary.',['status'=>400]);
        $uuid=Uuid::v4();$now=$this->db->now();$ok=$this->db->wpdb()->insert($this->db->table('transparency_records'),[
            'record_uuid'=>$uuid,'state'=>'draft','purpose'=>$purpose,'metric_ids_json'=>Json::canonical(array_values(array_map('strval',$metrics))),'data_classes_json'=>Json::canonical(array_values(array_map('strval',$classes))),'retention_summary'=>$retention,'approved_by'=>null,'published_at'=>null,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return $ok===1?['transparency_record_uuid'=>$uuid]:new WP_Error('smai_future_transparency_store_failed','Transparency record could not be persisted.',['status'=>500]);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function clean(array $value): array
    {
        $out=[];foreach(array_slice($value,0,100,true) as $k=>$v){if(str_starts_with((string)$k,'_'))continue;$out[Text::truncate((string)$k,100)]=$this->safe($v,0);}return $out;
    }

    private function safe(mixed $value,int $depth): mixed
    {
        if($depth>5)return '[truncated]';if(is_string($value))return Text::truncate(wp_strip_all_tags($value),1000);if(is_scalar($value)||$value===null)return$value;if(is_array($value)){$out=[];foreach(array_slice($value,0,100,true) as $k=>$v)$out[(string)$k]=$this->safe($v,$depth+1);return$out;}return '[unsupported]';
    }

    private function number(mixed $value): ?float
    {
        if(!(is_int($value)||is_float($value)||(is_string($value)&&is_numeric($value))))return null;$n=(float)$value;return is_finite($n)?$n:null;
    }
}
