<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use WP_Error;

final class RuntimeService
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function registerDataset(array $input, int $actor): array|WP_Error
    {
        $required = ['dataset_id','dataset_version','owner_module','grain','privacy_class','schema','retention_days'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing required dataset field: ' . $field, ['status' => 400]);
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', (string) $input['dataset_id'])) {
            return new WP_Error('invalid_dataset_id', 'Invalid dataset identifier.', ['status' => 400]);
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) $input['dataset_version'])) {
            return new WP_Error('invalid_version', 'Dataset version must be semantic.', ['status' => 400]);
        }
        if (!in_array((string) $input['privacy_class'], ['C1','C2','C3'], true)) {
            return new WP_Error('invalid_privacy_class', 'Only C1-C3 derivative datasets are allowed.', ['status' => 400]);
        }
        $schema = is_array($input['schema']) ? $input['schema'] : [];
        if ($this->containsForbiddenField($schema)) {
            return new WP_Error('forbidden_schema', 'Dataset schema contains prohibited raw or sensitive fields.', ['status' => 422]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'smai_datasets';
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($table, [
            'dataset_id' => sanitize_key((string) $input['dataset_id']),
            'dataset_version' => sanitize_text_field((string) $input['dataset_version']),
            'owner_module' => sanitize_key((string) $input['owner_module']),
            'state' => 'draft',
            'grain' => sanitize_text_field((string) $input['grain']),
            'privacy_class' => (string) $input['privacy_class'],
            'region_code' => sanitize_text_field((string) ($input['region_code'] ?? 'PK')),
            'provider_ref' => isset($input['provider_ref']) ? sanitize_text_field((string) $input['provider_ref']) : null,
            'schema_json' => wp_json_encode($schema, JSON_UNESCAPED_SLASHES),
            'retention_days' => max(1, min(3650, (int) $input['retention_days'])),
            'lineage_complete' => 0,
            'row_version' => 1,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return new WP_Error('dataset_conflict', 'Dataset contract already exists or could not be stored.', ['status' => 409]);
        }
        return ['id' => (int) $wpdb->insert_id, 'state' => 'draft', 'row_version' => 1];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function addLineage(array $input, int $actor): array|WP_Error
    {
        foreach (['from_type','from_ref','from_version','to_type','to_ref','to_version','transform_ref','owner_module'] as $field) {
            if (empty($input[$field])) {
                return new WP_Error('missing_field', 'Missing lineage field: ' . $field, ['status' => 400]);
            }
        }
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $ok = $wpdb->insert($wpdb->prefix . 'smai_lineage_edges', [
            'edge_uuid' => $uuid,
            'from_type' => sanitize_key((string) $input['from_type']),
            'from_ref' => sanitize_text_field((string) $input['from_ref']),
            'from_version' => sanitize_text_field((string) $input['from_version']),
            'to_type' => sanitize_key((string) $input['to_type']),
            'to_ref' => sanitize_text_field((string) $input['to_ref']),
            'to_version' => sanitize_text_field((string) $input['to_version']),
            'transform_ref' => sanitize_text_field((string) $input['transform_ref']),
            'code_sha' => isset($input['code_sha']) && preg_match('/^[a-f0-9]{40}$/', (string) $input['code_sha']) ? (string) $input['code_sha'] : null,
            'job_uuid' => isset($input['job_uuid']) ? sanitize_text_field((string) $input['job_uuid']) : null,
            'owner_module' => sanitize_key((string) $input['owner_module']),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok !== 1) {
            return new WP_Error('lineage_conflict', 'Lineage edge could not be stored.', ['status' => 409]);
        }
        $this->audit($actor, 'lineage.created', 'lineage_edge', $uuid, 'success', ['to_ref' => (string) $input['to_ref']]);
        return ['edge_uuid' => $uuid];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function createPipelineJob(array $input, int $actor): array|WP_Error
    {
        foreach (['job_type','dataset_ref','dataset_version','scope','idempotency_key'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing pipeline field: ' . $field, ['status' => 400]);
            }
        }
        if (!in_array((string) $input['job_type'], ['backfill','rebuild','snapshot','deletion_recompute','provider_exit','restore'], true)) {
            return new WP_Error('invalid_job_type', 'Unsupported pipeline job type.', ['status' => 400]);
        }
        $key = hash('sha256', (string) $input['idempotency_key']);
        global $wpdb;
        $table = $wpdb->prefix . 'smai_pipeline_jobs';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT job_uuid,state FROM {$table} WHERE idempotency_key=%s", $key), ARRAY_A);
        if (is_array($existing)) {
            return ['job_uuid' => (string) $existing['job_uuid'], 'state' => (string) $existing['state'], 'duplicate' => true];
        }
        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($table, [
            'job_uuid' => $uuid,
            'job_type' => (string) $input['job_type'],
            'dataset_ref' => sanitize_text_field((string) $input['dataset_ref']),
            'dataset_version' => sanitize_text_field((string) $input['dataset_version']),
            'state' => 'planned',
            'scope_json' => wp_json_encode(is_array($input['scope']) ? $input['scope'] : []),
            'idempotency_key' => $key,
            'requested_by' => $actor,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('job_create_failed', 'Pipeline job could not be created.', ['status' => 500]);
        }
        return ['job_uuid' => $uuid, 'state' => 'planned', 'duplicate' => false];
    }

    /** @return array<string,mixed>|WP_Error */
    public function transitionPipelineJob(string $uuid, string $target, int $actor, string $reason): array|WP_Error
    {
        $map = [
            'planned' => ['dry_run','cancelled'],
            'dry_run' => ['shadow_build','failed','cancelled'],
            'shadow_build' => ['compared','failed','cancelled'],
            'compared' => ['approved','failed','cancelled'],
            'approved' => ['activated','rolled_back','failed'],
            'activated' => ['closed','rolled_back'],
            'rolled_back' => ['closed'],
            'failed' => ['planned','closed'],
            'cancelled' => ['closed'],
            'closed' => [],
        ];
        global $wpdb;
        $table = $wpdb->prefix . 'smai_pipeline_jobs';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE job_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($row)) {
            return new WP_Error('job_not_found', 'Pipeline job not found.', ['status' => 404]);
        }
        $from = (string) $row['state'];
        if (!in_array($target, $map[$from] ?? [], true)) {
            return new WP_Error('invalid_transition', 'Invalid pipeline transition.', ['status' => 409]);
        }
        if ($target === 'approved' && (int) $row['requested_by'] === $actor) {
            return new WP_Error('separation_of_duties', 'Requester cannot approve the same pipeline job.', ['status' => 403]);
        }
        $data = ['state' => $target, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($target === 'dry_run') {
            $data['started_at'] = gmdate('Y-m-d H:i:s');
        }
        if (in_array($target, ['closed','rolled_back','cancelled'], true)) {
            $data['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($target === 'approved') {
            $data['approved_by'] = $actor;
        }
        $updated = $wpdb->update($table, $data, ['job_uuid' => $uuid, 'state' => $from]);
        if ($updated !== 1) {
            return new WP_Error('stale_job', 'Pipeline job changed concurrently.', ['status' => 409]);
        }
        $this->audit($actor, 'pipeline.transition', 'pipeline_job', $uuid, 'success', ['from' => $from, 'to' => $target, 'reason' => sanitize_text_field($reason)]);
        return ['job_uuid' => $uuid, 'from' => $from, 'state' => $target];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function createExperiment(array $input, int $actor): array|WP_Error
    {
        foreach (['name','hypothesis','owner_module','assignment_authority','audience','primary_metrics','guardrail_metrics','design'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing experiment field: ' . $field, ['status' => 400]);
            }
        }
        $design = is_array($input['design']) ? $input['design'] : [];
        foreach (['sample_size','duration_days','exclusions','analysis_plan'] as $field) {
            if (!array_key_exists($field, $design)) {
                return new WP_Error('invalid_design', 'Experiment design must predeclare ' . $field . '.', ['status' => 422]);
            }
        }
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($wpdb->prefix . 'smai_experiments', [
            'experiment_uuid' => $uuid,
            'name' => sanitize_text_field((string) $input['name']),
            'hypothesis' => sanitize_textarea_field((string) $input['hypothesis']),
            'owner_module' => sanitize_key((string) $input['owner_module']),
            'assignment_authority' => sanitize_key((string) $input['assignment_authority']),
            'state' => 'proposed',
            'audience_json' => wp_json_encode((array) $input['audience']),
            'primary_metrics_json' => wp_json_encode((array) $input['primary_metrics']),
            'guardrail_metrics_json' => wp_json_encode((array) $input['guardrail_metrics']),
            'design_json' => wp_json_encode($design),
            'row_version' => 1,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('experiment_create_failed', 'Experiment could not be created.', ['status' => 500]);
        }
        return ['experiment_uuid' => $uuid, 'state' => 'proposed', 'row_version' => 1];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
    public function createDecision(array $input, int $actor): array|WP_Error
    {
        foreach (['title','metric_evidence','alternatives','risks','recommendation','approved_action','approver_user_id'] as $field) {
            if (!array_key_exists($field, $input)) {
                return new WP_Error('missing_field', 'Missing decision field: ' . $field, ['status' => 400]);
            }
        }
        if ($actor === (int) $input['approver_user_id']) {
            return new WP_Error('separation_of_duties', 'Decision owner and approver must differ.', ['status' => 403]);
        }
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($wpdb->prefix . 'smai_decision_records', [
            'decision_uuid' => $uuid,
            'title' => sanitize_text_field((string) $input['title']),
            'owner_user_id' => $actor,
            'approver_user_id' => (int) $input['approver_user_id'],
            'metric_evidence_json' => wp_json_encode((array) $input['metric_evidence']),
            'alternatives_json' => wp_json_encode((array) $input['alternatives']),
            'risks_json' => wp_json_encode((array) $input['risks']),
            'recommendation' => sanitize_textarea_field((string) $input['recommendation']),
            'approved_action' => sanitize_textarea_field((string) $input['approved_action']),
            'review_due_at' => isset($input['review_due_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $input['review_due_at'])) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('decision_create_failed', 'Decision record could not be created.', ['status' => 500]);
        }
        return ['decision_uuid' => $uuid, 'automation' => false];
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'smai_';
        $tables = ['datasets','lineage_edges','pipeline_jobs','quality_rules','scheduled_reports','report_runs','experiments','decision_records','retention_holds','provider_registry'];
        $status = [];
        foreach ($tables as $name) {
            $table = $prefix . $name;
            $status[$name] = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        $lag = $wpdb->get_var("SELECT TIMESTAMPDIFF(SECOND, MAX(recorded_at), UTC_TIMESTAMP()) FROM {$prefix}events");
        return [
            'schema_version' => (string) get_option('smai_schema_version', ''),
            'runtime_state' => (string) get_option('smai_runtime_state', 'foundation_disabled'),
            'tables' => $status,
            'pipeline_lag_seconds' => $lag === null ? null : max(0, (int) $lag),
            'fail_closed' => true,
        ];
    }

    /** @param array<string,mixed> $schema */
    private function containsForbiddenField(array $schema): bool
    {
        $encoded = strtolower((string) wp_json_encode($schema));
        foreach (['password','otp','private_key','api_key','access_token','clinical_note','prescription','message_body','pan','cvv','identity_document','national_id'] as $needle) {
            if (str_contains($encoded, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $context */
    private function audit(int $actor, string $action, string $objectType, string $objectRef, string $result, array $context): void
    {
        do_action('smai_audit_event', [
            'actor_user_id' => $actor,
            'actor_type' => 'user',
            'action' => $action,
            'object_type' => $objectType,
            'object_ref' => $objectRef,
            'purpose' => 'analytics_governance',
            'result' => $result,
            'context' => $context,
        ]);
    }
}
