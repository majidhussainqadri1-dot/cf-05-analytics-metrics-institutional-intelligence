<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Contracts\ExperimentDefinitionValidator;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class ExperimentService
{
    private Database $db;
    private AuditLogger $audit;

    private const TRANSITIONS = [
        'proposed' => ['reviewed'],
        'reviewed' => ['scheduled'],
        'scheduled' => ['running','stopped'],
        'running' => ['paused','stopped'],
        'paused' => ['running','stopped'],
        'stopped' => [],
        'analyzed' => ['decided'],
        'decided' => ['archived'],
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $definition */
    public function create(array $definition, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_experiments')) {
            return new WP_Error('smai_experiment_forbidden', 'Experiment creation is not authorized.', ['status'=>403]);
        }
        $errors = (new ExperimentDefinitionValidator())->errors($definition);
        if ($errors !== []) {
            return new WP_Error('smai_invalid_experiment', 'Experiment validation failed.', ['status' => 400, 'errors' => $errors]);
        }
        if ((new SensitiveValueDetector())->violations($definition) !== []) {
            return new WP_Error('smai_sensitive_experiment_definition', 'Experiment definition contains prohibited sensitive material.', ['status' => 400]);
        }
        foreach (array_merge((array) $definition['primary_metrics'], (array) $definition['guardrails']) as $metricSpec) {
            if (!is_array($metricSpec) || (new MetricCatalog($this->db))->active((string) $metricSpec['metric_id'], (string) $metricSpec['metric_version']) === null) {
                return new WP_Error('smai_experiment_metric_inactive', 'Experiment metric contract is not active.', ['status' => 409]);
            }
        }
        $enhanced = (string) $definition['privacy_class'] === 'C3' || !empty($definition['involves_minors']) || !empty($definition['medical_context']);
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $startsAtTs=!empty($definition['starts_at'])?$this->strictTimestamp((string)$definition['starts_at']):null; $endsAtTs=!empty($definition['ends_at'])?$this->strictTimestamp((string)$definition['ends_at']):null;
        $startsAt=$startsAtTs!==null?gmdate('Y-m-d H:i:s',$startsAtTs):null; $endsAt=$endsAtTs!==null?gmdate('Y-m-d H:i:s',$endsAtTs):null;
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_experiment_transaction_failed','Experiment transaction could not start.',['status'=>500]);
        $ok = $wpdb->insert($this->db->table('experiments'), [
            'experiment_uuid' => $uuid,
            'name' => Text::truncate(trim(wp_strip_all_tags((string) $definition['name'])), 190),
            'state' => 'proposed',
            'hypothesis' => Text::truncate(trim(wp_strip_all_tags((string) $definition['hypothesis'])), 5000),
            'owner_user_id' => $actorUserId,
            'assignment_owner' => $definition['assignment_owner'],
            'audience_json' => Json::canonical($definition['audience']),
            'metrics_json' => Json::canonical(array_values($definition['primary_metrics'])),
            'guardrails_json' => Json::canonical(array_values($definition['guardrails'])),
            'design_json' => Json::canonical($definition['design']),
            'privacy_class' => $definition['privacy_class'],
            'enhanced_review' => $enhanced ? 1 : 0,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1 || !$this->audit->logInOpenTransaction('experiment_created', 'experiment', $uuid, 'success', ['enhanced_review' => $enhanced], 'experiment_governance', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_experiment_store_failed', 'Experiment could not be stored.', ['status' => 500]);
        }
        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_experiment_commit_failed','Experiment could not be committed.',['status'=>500]); }
        return ['experiment_uuid' => $uuid, 'state' => 'proposed', 'row_version' => 1, 'enhanced_review' => $enhanced];
    }

    public function transition(string $uuid, string $target, int $expectedVersion, string $reason, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_experiments')) {
            return new WP_Error('smai_experiment_forbidden', 'Experiment transition is not authorized.', ['status'=>403]);
        }
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if ($expectedVersion < 1 || preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1
            || strlen($reason) < 8 || (new SensitiveValueDetector())->violations($reason) !== []) {
            return new WP_Error('smai_invalid_experiment_transition', 'Experiment transition request is invalid.', ['status' => 400]);
        }
        $table = $this->db->table('experiments');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE experiment_uuid=%s", $uuid), ARRAY_A);
        if (!is_array($row) || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_experiment_stale', 'Experiment is unavailable or stale.', ['status' => 409]);
        }
        $from = (string) $row['state'];
        if (!in_array($target, self::TRANSITIONS[$from] ?? [], true)) {
            return new WP_Error('smai_invalid_experiment_transition', 'Experiment transition is not allowed.', ['status' => 409]);
        }
        if (in_array($target, ['reviewed','scheduled'], true) && (int) $row['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Independent experiment review is required.', ['status' => 403]);
        }
        if ((int) $row['enhanced_review'] === 1 && $target === 'scheduled' && !user_can($actorUserId, 'smai_approve_catalog')) {
            return new WP_Error('smai_enhanced_review_required', 'Enhanced privacy/safety approval is required.', ['status' => 403]);
        }
        if ($target === 'scheduled' && ($row['starts_at'] === null || $row['ends_at'] === null || strtotime((string) $row['ends_at']) <= strtotime((string) $row['starts_at']))) {
            return new WP_Error('smai_experiment_window_required', 'A valid experiment window is required before scheduling.', ['status' => 409]);
        }
        if ($target === 'running' && (($row['starts_at'] !== null && time() < strtotime((string) $row['starts_at'])) || ($row['ends_at'] !== null && time() >= strtotime((string) $row['ends_at'])))) { return new WP_Error('smai_experiment_outside_window', 'Experiment cannot run outside its approved window.', ['status' => 409]); }
        if (in_array($target,['scheduled','running'],true) && !$this->metricsAvailableForExperiment($row)) return new WP_Error('smai_experiment_metric_stale','Experiment cannot advance because a governed metric or guardrail contract is no longer active or privacy-valid.',['status'=>409]);
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_experiment_transaction_failed','Experiment transition transaction could not start.',['status'=>500]);
        $updated = $wpdb->update($table, [
            'state' => $target,
            'approved_by' => $target === 'reviewed' ? $actorUserId : $row['approved_by'],
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'state' => $from, 'row_version' => $expectedVersion]);
        if ($updated !== 1 || !$this->audit->logInOpenTransaction('experiment_transition', 'experiment', $uuid, 'success', ['from' => $from, 'to' => $target, 'reason' => $reason], 'experiment_governance', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_experiment_conflict', 'Experiment changed concurrently.', ['status' => 409]);
        }
        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_experiment_commit_failed','Experiment transition could not be committed.',['status'=>500]); }
        return ['experiment_uuid' => $uuid, 'state' => $target, 'row_version' => $expectedVersion + 1];
    }

    /** @param array<string,mixed> $fact */
    public function recordAssignment(array $fact, string $service): array|WP_Error
    {
        if (array_diff(array_keys($fact), ['experiment_uuid','assignment_event_id','subject_ref','deletion_key','variant_key','assignment_owner','occurred_at']) !== []
            || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $service) !== 1) {
            return new WP_Error('smai_invalid_assignment_fact', 'Assignment fact contains unsupported fields.', ['status' => 400]);
        }
        foreach (['experiment_uuid','assignment_event_id','subject_ref','deletion_key','variant_key','assignment_owner','occurred_at'] as $key) {
            if (!isset($fact[$key])) {
                return new WP_Error('smai_invalid_assignment_fact', 'Assignment fact is incomplete.', ['status' => 400]);
            }
        }
        $experiment = $this->get((string) $fact['experiment_uuid']);
        if ($experiment === null || !in_array((string) $experiment['state'], ['scheduled','running'], true)) {
            return new WP_Error('smai_experiment_not_eligible', 'Experiment is not eligible for assignment facts.', ['status' => 409]);
        }
        if (!hash_equals((string) $experiment['assignment_owner'], (string) $fact['assignment_owner']) || !hash_equals((string) $experiment['assignment_owner'], $service)) {
            return new WP_Error('smai_assignment_owner_mismatch', 'Assignment owner mismatch.', ['status' => 403]);
        }
        $occurred = $this->strictTimestamp((string) $fact['occurred_at']);
        if (preg_match('/^[a-f0-9]{64}$/', (string) $fact['subject_ref']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) $fact['deletion_key']) !== 1
            || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', (string) $fact['assignment_event_id']) !== 1
            || $occurred === false || $occurred > time() + 300) {
            return new WP_Error('smai_invalid_assignment_fact', 'Assignment fact validation failed.', ['status' => 400]);
        }
        if (($experiment['starts_at'] !== null && $occurred < strtotime((string) $experiment['starts_at'])) || ($experiment['ends_at'] !== null && $occurred >= strtotime((string) $experiment['ends_at']))) {
            return new WP_Error('smai_assignment_outside_window', 'Assignment occurred outside the approved window.', ['status' => 409]);
        }
        $variants = $this->variantKeys(Json::object((string) $experiment['design_json']));
        if (!in_array((string) $fact['variant_key'], $variants, true)) {
            return new WP_Error('smai_unknown_variant', 'Assignment variant is not predeclared.', ['status' => 400]);
        }
        $canonical = [
            'experiment_uuid' => (string) $fact['experiment_uuid'],
            'assignment_event_id' => strtolower((string) $fact['assignment_event_id']),
            'subject_ref' => (string) $fact['subject_ref'],
            'deletion_key' => (string) $fact['deletion_key'],
            'variant_key' => (string) $fact['variant_key'],
            'assignment_owner' => (string) $fact['assignment_owner'],
            'occurred_at' => gmdate('Y-m-d H:i:s', $occurred),
        ];
        $hash = hash('sha256', Json::canonical($canonical));
        $table = $this->db->table('experiment_facts');
        $lockName = 'smai_exp_' . substr(hash('sha256', $canonical['experiment_uuid'] . '|' . $canonical['subject_ref']), 0, 55);
        $acquired = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT GET_LOCK(%s,5)', $lockName)) === 1;
        if (!$acquired) {
            return new WP_Error('smai_assignment_lock_timeout', 'Assignment is being processed concurrently.', ['status' => 409]);
        }
        try {
            $subjectExisting = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT assignment_event_id,variant_key,deletion_key FROM `{$table}` WHERE experiment_uuid=%s AND subject_ref=%s LIMIT 1", $canonical['experiment_uuid'], $canonical['subject_ref']), ARRAY_A);
            if (is_array($subjectExisting)) {
                if (!hash_equals((string) $subjectExisting['variant_key'], $canonical['variant_key'])
                    || !hash_equals((string) $subjectExisting['deletion_key'], $canonical['deletion_key'])) {
                    return new WP_Error('smai_subject_assignment_collision', 'Subject was already assigned with conflicting governed assignment data.', ['status' => 409]);
                }
                return ['assignment_event_id' => $subjectExisting['assignment_event_id'], 'status' => 'subject_already_assigned'];
            }
            $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT fact_hash FROM `{$table}` WHERE assignment_event_id=%s", $canonical['assignment_event_id']), ARRAY_A);
            if (is_array($existing)) {
                return hash_equals((string) $existing['fact_hash'], $hash)
                    ? ['assignment_event_id' => $canonical['assignment_event_id'], 'status' => 'duplicate_ignored']
                    : new WP_Error('smai_assignment_id_collision', 'Assignment event ID was reused with different content.', ['status' => 409]);
            }
            $wpdb = $this->db->wpdb();
            if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_assignment_transaction_failed','Assignment transaction could not start.',['status'=>500]);
            $inserted = $wpdb->insert($table, [
                'experiment_uuid' => $canonical['experiment_uuid'],
                'assignment_event_id' => $canonical['assignment_event_id'],
                'subject_ref' => $canonical['subject_ref'],
                'experiment_subject' => $canonical['subject_ref'],
                'deletion_key' => $canonical['deletion_key'],
                'variant_key' => Text::truncate($canonical['variant_key'], 100),
                'assignment_owner' => $canonical['assignment_owner'],
                'occurred_at' => $canonical['occurred_at'],
                'fact_hash' => $hash,
                'created_at' => $this->db->now(),
            ]);
            if ($inserted !== 1 || !$this->audit->logInOpenTransaction('experiment_assignment_recorded', 'experiment_assignment', $canonical['assignment_event_id'], 'success', ['experiment_uuid' => $canonical['experiment_uuid'], 'variant_key' => $canonical['variant_key']], 'experiment_assignment', null, null, 'service')) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('smai_assignment_store_failed', 'Assignment fact could not be stored.', ['status' => 500]);
            }
            if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_assignment_commit_failed','Assignment fact could not be committed.',['status'=>500]); }
            return ['assignment_event_id' => $canonical['assignment_event_id'], 'status' => 'accepted'];
        } finally {
            $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public function analyze(string $uuid, string $analysisVersion, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_experiments')) {
            return new WP_Error('smai_experiment_forbidden', 'Experiment analysis is not authorized.', ['status'=>403]);
        }
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1 || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $analysisVersion) !== 1) {
            return new WP_Error('smai_invalid_analysis_version', 'Analysis version must be semantic.', ['status' => 400]);
        }
        $experiment = $this->get($uuid);
        if ($experiment === null || !in_array((string) $experiment['state'], ['running','stopped'], true)) {
            return new WP_Error('smai_experiment_not_analyzable', 'Experiment is not ready for analysis.', ['status' => 409]);
        }
        $design = Json::object((string) $experiment['design_json']);
        $variants = $this->variantKeys($design);
        $counts = $this->variantCounts($uuid);
        $minimum = max(20, (int) ($design['minimum_sample'] ?? 20));
        $minimumPerVariant = max(5, (int) ceil($minimum / max(2, count($variants))));
        $disclosedCounts = [];
        foreach ($variants as $variant) {
            $count = (int) ($counts[$variant] ?? 0);
            $disclosedCounts[$variant] = $count >= $minimumPerVariant ? $count : null;
        }
        $result = [
            'experiment_uuid' => $uuid,
            'analysis_version' => $analysisVersion,
            'assignment_counts' => $disclosedCounts,
            'metrics' => [],
            'guardrails' => [],
            'predeclared' => [
                'variants' => $variants,
                'minimum_sample' => $minimum,
                'minimum_per_variant' => $minimumPerVariant,
                'duration_days' => (int) ($design['duration_days'] ?? 0),
                'multiple_testing_policy' => $design['multiple_testing_policy'] ?? null,
                'missing_data_policy' => $design['missing_data_policy'] ?? null,
                'minimum_practical_effect' => (float) ($design['minimum_practical_effect'] ?? 0.0),
            ],
            'deviations' => [],
            'guardrail_breached' => false,
            'conclusion' => 'inconclusive',
        ];
        if (array_sum($counts) < $minimum) {
            $result['deviations'][] = 'Total assignment sample is below the predeclared minimum.';
        }
        foreach ($variants as $variant) {
            if (($counts[$variant] ?? 0) < $minimumPerVariant) {
                $result['deviations'][] = 'Variant ' . $variant . ' is below the minimum sample.';
            }
        }
        foreach (Json::list((string) $experiment['metrics_json']) as $metricSpec) {
            if (is_array($metricSpec)) {
                $result['metrics'][] = $this->analyzeMetric($uuid, $variants, $metricSpec, (float) ($design['minimum_practical_effect'] ?? 0.0));
            }
        }
        $this->applyMultipleTestingPolicy($result['metrics'], (string) ($design['multiple_testing_policy'] ?? 'single_primary'));

        foreach (Json::list((string) $experiment['guardrails_json']) as $guardrailSpec) {
            if (!is_array($guardrailSpec)) {
                continue;
            }
            $analysis = $this->analyzeMetric($uuid, $variants, $guardrailSpec, (float) ($guardrailSpec['minimum_effect'] ?? 0.0));
            $analysis['breached'] = $this->guardrailBreached($analysis, $guardrailSpec);
            if ($analysis['breached']) {
                $result['guardrail_breached'] = true;
            }
            $result['guardrails'][] = $analysis;
        }
        $primary = $result['metrics'][0] ?? null;
        if ($result['guardrail_breached']) {
            $result['conclusion'] = 'guardrail_breached';
        } elseif ($result['deviations'] !== []) {
            $result['conclusion'] = 'inconclusive_protocol_or_sample';
        } elseif (is_array($primary) && $this->hasConclusivePracticalComparison($primary)) {
            $result['conclusion'] = 'evidence_supports_predeclared_effect';
        } elseif (is_array($primary) && in_array((string) ($primary['status'] ?? ''), ['compared','partially_compared'], true)) {
            $result['conclusion'] = 'inconclusive_no_predeclared_effect';
        } else {
            $result['conclusion'] = 'inconclusive_missing_approved_snapshots';
        }
        $json = Json::canonical($result);
        $hash = hash('sha256', $json);
        $table = $this->db->table('experiment_analyses');
        $existing = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT id,analysis_uuid,state,result_hash FROM `{$table}` WHERE experiment_uuid=%s AND analysis_version=%s", $uuid, $analysisVersion), ARRAY_A);
        if (is_array($existing)) {
            if (hash_equals((string) $existing['result_hash'], $hash)) {
                return ['analysis_uuid' => $existing['analysis_uuid'], 'status' => (string) $existing['state'], 'unchanged' => true, 'result' => $result];
            }
            return new WP_Error('smai_analysis_immutable', 'Existing analysis version is immutable.', ['status' => 409]);
        }
        $analysisUuid = Uuid::v4();
        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_analysis_transaction_failed','Analysis transaction could not start.',['status'=>500]);
        $ok = $wpdb->insert($table, [
            'analysis_uuid' => $analysisUuid,
            'experiment_uuid' => $uuid,
            'analysis_version' => $analysisVersion,
            'state' => 'draft',
            'result_json' => $json,
            'result_hash' => $hash,
            'deviations_json' => Json::canonical($result['deviations']),
            'analyst_user_id' => $actorUserId,
            'reviewer_user_id' => null,
            'row_version' => 1,
            'created_at' => $now,
            'published_at' => null,
            'updated_at' => $now,
        ]);
        if ($ok !== 1 || !$this->audit->logInOpenTransaction('experiment_analysis_created', 'analysis', $analysisUuid, 'success', ['experiment_uuid' => $uuid, 'analysis_version' => $analysisVersion, 'result_hash' => $hash, 'conclusion' => $result['conclusion']], 'experiment_analysis', null, $actorUserId)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_analysis_store_failed', 'Analysis could not be stored.', ['status' => 500]);
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_analysis_commit_failed', 'Analysis could not be committed.', ['status'=>500]); }
        if ($result['guardrail_breached']) {
            do_action('smai_experiment_guardrail_breached', ['experiment_uuid' => $uuid, 'analysis_version' => $analysisVersion, 'guardrails' => $result['guardrails']]);
        }
        return ['analysis_uuid' => $analysisUuid, 'status' => 'draft', 'row_version' => 1, 'result' => $result];
    }

    public function publishAnalysis(string $analysisUuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_approve_catalog')) {
            return new WP_Error('smai_experiment_forbidden', 'Analysis publication is not authorized.', ['status'=>403]);
        }
        if ($expectedVersion < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $analysisUuid) !== 1) {
            return new WP_Error('smai_invalid_analysis_publish', 'Analysis publication request is invalid.', ['status' => 400]);
        }
        $analysisTable = $this->db->table('experiment_analyses');
        $experimentTable = $this->db->table('experiments');
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('smai_analysis_transaction_failed','Analysis publication transaction could not start.',['status'=>500]);
        try {
            $analysis = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$analysisTable}` WHERE analysis_uuid=%s FOR UPDATE", $analysisUuid), ARRAY_A);
            if (!is_array($analysis) || (string) $analysis['state'] !== 'draft' || (int) $analysis['row_version'] !== $expectedVersion) {
                throw new \DomainException('analysis_stale');
            }
            if ((int) $analysis['analyst_user_id'] === $actorUserId) {
                throw new \DomainException('separation_of_duties');
            }
            $experiment = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$experimentTable}` WHERE experiment_uuid=%s FOR UPDATE", $analysis['experiment_uuid']), ARRAY_A);
            if (!is_array($experiment) || (string) $experiment['state'] !== 'stopped') {
                throw new \DomainException('experiment_not_stopped');
            }
            if (!$this->metricsAvailableForExperiment($experiment)) {
                throw new \DomainException('experiment_metric_stale');
            }
            if (!$this->analysisResultStillPublishable(Json::object((string) $analysis['result_json']))) {
                throw new \DomainException('analysis_evidence_stale');
            }
            $now = $this->db->now();
            if ($wpdb->update($analysisTable, [
                'state' => 'published',
                'reviewer_user_id' => $actorUserId,
                'row_version' => $expectedVersion + 1,
                'published_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int) $analysis['id'], 'state' => 'draft', 'row_version' => $expectedVersion]) !== 1) {
                throw new \RuntimeException('analysis_conflict');
            }
            if ($wpdb->update($experimentTable, [
                'state' => 'analyzed',
                'row_version' => (int) $experiment['row_version'] + 1,
                'updated_at' => $now,
            ], ['id' => (int) $experiment['id'], 'state' => 'stopped', 'row_version' => (int) $experiment['row_version']]) !== 1) {
                throw new \RuntimeException('experiment_conflict');
            }
            if (!$this->audit->logInOpenTransaction('experiment_analysis_published', 'analysis', $analysisUuid, 'success', ['experiment_uuid' => $analysis['experiment_uuid'], 'analysis_version' => $analysis['analysis_version']], 'experiment_analysis', null, $actorUserId)) { throw new \RuntimeException('audit_failed'); }
            if ($wpdb->query('COMMIT')===false) throw new \RuntimeException('commit_failed');
            return ['analysis_uuid' => $analysisUuid, 'status' => 'published', 'row_version' => $expectedVersion + 1, 'experiment_state' => 'analyzed'];
        } catch (\DomainException $error) {
            $wpdb->query('ROLLBACK');
            return match ($error->getMessage()) {
                'separation_of_duties' => new WP_Error('smai_separation_of_duties', 'Analyst cannot publish their own analysis.', ['status' => 403]),
                'experiment_not_stopped' => new WP_Error('smai_experiment_not_stopped', 'Formal analysis publication requires a stopped experiment.', ['status' => 409]),
                'experiment_metric_stale' => new WP_Error('smai_experiment_metric_stale', 'Analysis publication is blocked because a governed metric or guardrail contract is no longer active or privacy-valid.', ['status' => 409]),
                'analysis_evidence_stale' => new WP_Error('smai_analysis_evidence_stale', 'Analysis publication is blocked because cited snapshot evidence is no longer publishable under current privacy or quality rules.', ['status' => 409]),
                default => new WP_Error('smai_analysis_stale', 'Analysis is unavailable or stale.', ['status' => 409]),
            };
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_analysis_publish_failed', 'Analysis could not be published safely.', ['status' => 409]);
        }
    }

    /** @param array<string,mixed> $record */
    public function recordDecision(string $subjectType, string $subjectRef, array $record, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_experiments')) {
            return new WP_Error('smai_decision_forbidden', 'Decision recording is not authorized.', ['status'=>403]);
        }
        $allowed = ['evidence','alternatives','risks','action_owner','decision','review_at'];
        if (array_diff(array_keys($record), $allowed) !== []) {
            return new WP_Error('smai_invalid_decision_record', 'Decision record contains unsupported fields.', ['status' => 400]);
        }
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $record)) {
                return new WP_Error('smai_invalid_decision_record', 'Decision record is incomplete.', ['status' => 400]);
            }
        }
        $actionOwner = Text::truncate(trim(wp_strip_all_tags((string) $record['action_owner'])), 100);
        $decisionText = Text::truncate(trim(wp_strip_all_tags((string) $record['decision'])), 5000);
        $reviewAtTs=$this->strictTimestamp((string)$record['review_at']);
        if (!in_array($subjectType, ['experiment','analysis','metric','report'], true)
            || preg_match('/^[a-zA-Z0-9_.:@-]{3,190}$/', $subjectRef) !== 1
            || strlen($actionOwner) < 2
            || strlen($decisionText) < 10
            || $reviewAtTs === null
            || $reviewAtTs <= time()
            || (new SensitiveValueDetector())->violations($record) !== []) {
            return new WP_Error('smai_invalid_decision_record', 'Decision record validation failed; a future review date is required.', ['status' => 400]);
        }
        if (!$this->decisionSubjectExists($subjectType, $subjectRef)) {
            return new WP_Error('smai_decision_subject_unavailable', 'Decision must reference an existing governed subject; analysis subjects must be published.', ['status' => 409]);
        }
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_decision_transaction_failed','Decision transaction could not start.',['status'=>500]);}
        $ok = $wpdb->insert($this->db->table('decision_records'), [
            'decision_uuid' => $uuid,
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'evidence_json' => Json::canonical($record['evidence']),
            'alternatives_json' => Json::canonical($record['alternatives']),
            'risks_json' => Json::canonical($record['risks']),
            'action_owner' => $actionOwner,
            'decision_text' => $decisionText,
            'review_at' => gmdate('Y-m-d H:i:s',$reviewAtTs),
            'outcome_json' => null,
            'approver_user_id' => $actorUserId,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) { $wpdb->query('ROLLBACK');
            return new WP_Error('smai_decision_store_failed', 'Decision record could not be stored.', ['status' => 500]);
        }
        if(!$this->audit->logInOpenTransaction('decision_recorded', 'decision', $uuid, 'success', ['subject_type' => $subjectType, 'subject_ref' => $subjectRef], 'institutional_decision_support', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_decision_audit_failed','Decision was not committed because audit evidence failed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_decision_commit_failed','Decision could not be committed.',['status'=>500]);}
        return ['decision_uuid' => $uuid, 'status' => 'recorded', 'row_version' => 1];
    }

    /** @param array<string,mixed> $outcome */
    public function recordOutcome(string $decisionUuid, array $outcome, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_experiments')) {
            return new WP_Error('smai_decision_forbidden', 'Decision outcome recording is not authorized.', ['status'=>403]);
        }
        if ($expectedVersion < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $decisionUuid) !== 1
            || $outcome === [] || (new SensitiveValueDetector())->violations($outcome) !== []) {
            return new WP_Error('smai_invalid_decision_outcome', 'Decision outcome is invalid.', ['status' => 400]);
        }
        $table = $this->db->table('decision_records');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE decision_uuid=%s", $decisionUuid), ARRAY_A);
        if (!is_array($row) || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_decision_stale', 'Decision record is unavailable or stale.', ['status' => 409]);
        }
        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_decision_transaction_failed','Decision outcome transaction could not start.',['status'=>500]);}
        $updated = $wpdb->update($table, [
            'outcome_json' => Json::canonical($outcome),
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'row_version' => $expectedVersion]);
        if ($updated !== 1) { $wpdb->query('ROLLBACK');
            return new WP_Error('smai_decision_conflict', 'Decision record changed concurrently.', ['status' => 409]);
        }
        if(!$this->audit->logInOpenTransaction('decision_outcome_recorded', 'decision', $decisionUuid, 'success', [], 'institutional_decision_support', null, $actorUserId)){ $wpdb->query('ROLLBACK'); return new WP_Error('smai_decision_audit_failed','Decision outcome was not committed because audit evidence failed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_decision_commit_failed','Decision outcome could not be committed.',['status'=>500]);}
        return ['decision_uuid' => $decisionUuid, 'status' => 'outcome_recorded', 'row_version' => $expectedVersion + 1];
    }


    private function decisionSubjectExists(string $subjectType, string $subjectRef): bool
    {
        $wpdb = $this->db->wpdb();
        return match ($subjectType) {
            'experiment' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->db->table('experiments')}` WHERE experiment_uuid=%s",
                $subjectRef
            )) === 1,
            'analysis' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->db->table('experiment_analyses')}` WHERE analysis_uuid=%s AND state='published'",
                $subjectRef
            )) === 1,
            'report' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->db->table('reports')}` WHERE report_uuid=%s",
                $subjectRef
            )) === 1,
            'metric' => $this->metricDecisionSubjectExists($subjectRef),
            default => false,
        };
    }

    private function metricDecisionSubjectExists(string $subjectRef): bool
    {
        $bare = str_starts_with($subjectRef, 'metric:') ? substr($subjectRef, 7) : $subjectRef;
        if (preg_match('/^([a-z][a-z0-9_.-]{2,189})@([0-9]+\\.[0-9]+\\.[0-9]+)$/', $bare, $match) !== 1) {
            return false;
        }
        return (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
            "SELECT COUNT(*) FROM `{$this->db->table('metrics')}` WHERE metric_id=%s AND metric_version=%s",
            $match[1],
            $match[2]
        )) === 1;
    }

    /** @param array<string,mixed> $experiment */
    private function metricsAvailableForExperiment(array $experiment): bool
    {
        $specs=array_merge(Json::list((string)($experiment['metrics_json']??'[]')),Json::list((string)($experiment['guardrails_json']??'[]')));
        foreach($specs as $spec){ if(!is_array($spec))return false; $metric=(new MetricCatalog($this->db))->active((string)($spec['metric_id']??''),(string)($spec['metric_version']??'')); $dimensions=is_array($spec['base_dimensions']??null)?$spec['base_dimensions']:[]; if(!is_array($metric)||PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[])return false; }
        return true;
    }
    private function strictTimestamp(string $value): ?int { if(strlen($value)>35||preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/',$value,$m)!==1||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null; $t=strtotime($value);return $t===false?null:$t; }

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1) { return null; }
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('experiments')}` WHERE experiment_uuid=%s", $uuid), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<int,string> $variants @param array<string,mixed> $metricSpec @return array<string,mixed> */
    private function analyzeMetric(string $experimentUuid, array $variants, array $metricSpec, float $defaultMinimumEffect): array
    {
        $metricId = (string) ($metricSpec['metric_id'] ?? '');
        $version = (string) ($metricSpec['metric_version'] ?? '');
        $outcomeType = (string) ($metricSpec['outcome_type'] ?? 'ratio');
        $snapshots = [];
        foreach ($variants as $variant) {
            $dimensions = is_array($metricSpec['base_dimensions'] ?? null) ? $metricSpec['base_dimensions'] : [];
            $dimensions['experiment_uuid'] = $experimentUuid;
            $dimensions['variant_key'] = $variant;
            ksort($dimensions);
            $snapshots[$variant] = $this->latestSnapshot($metricId, $version, $dimensions);
        }
        $result = [
            'metric_id' => $metricId,
            'metric_version' => $version,
            'outcome_type' => $outcomeType,
            'variants' => $snapshots,
            'status' => 'unavailable',
            'comparison' => null,
            'comparisons' => [],
        ];
        if (count($variants) < 2 || !is_array($snapshots[$variants[0]] ?? null)) {
            return $result;
        }
        $controlKey = $variants[0];
        $control = $snapshots[$controlKey];
        $minimumEffect = isset($metricSpec['minimum_effect']) ? (float) $metricSpec['minimum_effect'] : $defaultMinimumEffect;
        foreach (array_slice($variants, 1) as $treatmentKey) {
            $treatment = $snapshots[$treatmentKey] ?? null;
            if (!is_array($treatment)) {
                continue;
            }
            $comparison = null;
            if ($outcomeType === 'ratio'
                && is_numeric($control['numerator'] ?? null) && is_numeric($control['denominator'] ?? null)
                && is_numeric($treatment['numerator'] ?? null) && is_numeric($treatment['denominator'] ?? null)
                && (float) $control['denominator'] > 0 && (float) $treatment['denominator'] > 0) {
                $comparison = Statistics::compareProportions(
                    (int) round((float) $control['numerator']),
                    (int) round((float) $control['denominator']),
                    (int) round((float) $treatment['numerator']),
                    (int) round((float) $treatment['denominator']),
                    $minimumEffect
                );
            } elseif (is_numeric($control['value'] ?? null) && is_numeric($treatment['value'] ?? null)) {
                $difference = (float) $treatment['value'] - (float) $control['value'];
                $comparison = [
                    'difference' => $difference,
                    'z_score' => null,
                    'p_value' => null,
                    'conclusive' => false,
                    'practical' => abs($difference) >= max(0.0, $minimumEffect),
                ];
            }
            if (is_array($comparison)) {
                $comparison = ['control' => $controlKey, 'treatment' => $treatmentKey] + $comparison;
                $result['comparisons'][] = $comparison;
            }
        }
        if ($result['comparisons'] === []) {
            return $result;
        }
        $result['comparison'] = $result['comparisons'][0];
        $result['status'] = count($result['comparisons']) === count($variants) - 1 ? 'compared' : 'partially_compared';
        return $result;
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed> $spec */
    private function guardrailBreached(array $analysis, array $spec): bool
    {
        $comparisons = is_array($analysis['comparisons'] ?? null) ? $analysis['comparisons'] : [];
        if ($comparisons === [] && is_array($analysis['comparison'] ?? null)) {
            $comparisons = [$analysis['comparison']];
        }
        $threshold = max(0.0, (float) ($spec['threshold'] ?? $spec['minimum_effect'] ?? 0.0));
        $direction = (string) ($spec['harm_direction'] ?? 'increase');
        foreach ($comparisons as $comparison) {
            if (!is_array($comparison) || !isset($comparison['difference'])) {
                continue;
            }
            $difference = (float) $comparison['difference'];
            if ($direction === 'decrease' ? $difference <= -$threshold : $difference >= $threshold) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $metrics */
    private function applyMultipleTestingPolicy(array &$metrics, string $policy): void
    {
        $refs = [];
        foreach ($metrics as $metricIndex => $metric) {
            foreach ((array) ($metric['comparisons'] ?? []) as $comparisonIndex => $comparison) {
                $p = $comparison['p_value'] ?? null;
                if (is_float($p) || is_int($p)) {
                    $refs[] = ['metric' => $metricIndex, 'comparison' => $comparisonIndex, 'p' => max(0.0, min(1.0, (float) $p))];
                }
            }
        }
        $m = count($refs);
        if ($m === 0) {
            return;
        }
        $adjusted = [];
        if ($policy === 'single_primary') {
            foreach ($refs as $index => $ref) {
                $adjusted[$index] = $index === 0 ? $ref['p'] : 1.0;
            }
        } elseif ($policy === 'bonferroni') {
            foreach ($refs as $index => $ref) {
                $adjusted[$index] = min(1.0, $ref['p'] * $m);
            }
        } elseif ($policy === 'holm') {
            $order = array_keys($refs);
            usort($order, static fn(int $a, int $b): int => $refs[$a]['p'] <=> $refs[$b]['p']);
            $running = 0.0;
            foreach ($order as $rank => $index) {
                $running = max($running, min(1.0, ($m - $rank) * $refs[$index]['p']));
                $adjusted[$index] = $running;
            }
        } elseif ($policy === 'fdr') {
            $order = array_keys($refs);
            usort($order, static fn(int $a, int $b): int => $refs[$a]['p'] <=> $refs[$b]['p']);
            $running = 1.0;
            for ($rank = $m - 1; $rank >= 0; $rank--) {
                $index = $order[$rank];
                $running = min($running, min(1.0, $refs[$index]['p'] * $m / ($rank + 1)));
                $adjusted[$index] = $running;
            }
        } else {
            foreach ($refs as $index => $ref) {
                $adjusted[$index] = 1.0;
            }
        }
        foreach ($refs as $index => $ref) {
            $mi = $ref['metric']; $ci = $ref['comparison'];
            $metrics[$mi]['comparisons'][$ci]['multiple_testing_policy'] = $policy;
            $metrics[$mi]['comparisons'][$ci]['adjusted_p_value'] = $adjusted[$index];
            $metrics[$mi]['comparisons'][$ci]['conclusive'] = $adjusted[$index] <= 0.05;
        }
        foreach ($metrics as $metricIndex => $metric) {
            if (($metrics[$metricIndex]['comparisons'] ?? []) !== []) {
                $metrics[$metricIndex]['comparison'] = $metrics[$metricIndex]['comparisons'][0];
            }
        }
    }

    /** @param array<string,mixed> $metric */
    private function hasConclusivePracticalComparison(array $metric): bool
    {
        foreach ((array) ($metric['comparisons'] ?? []) as $comparison) {
            if (is_array($comparison) && !empty($comparison['conclusive']) && !empty($comparison['practical'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $result */
    private function analysisResultStillPublishable(array $result): bool
    {
        foreach (['metrics','guardrails'] as $section) {
            foreach ((array) ($result[$section] ?? []) as $metricResult) {
                if (!is_array($metricResult)) {
                    return false;
                }
                $metricId = (string) ($metricResult['metric_id'] ?? '');
                $version = (string) ($metricResult['metric_version'] ?? '');
                foreach ((array) ($metricResult['variants'] ?? []) as $snapshot) {
                    if ($snapshot === null) {
                        continue;
                    }
                    if (!is_array($snapshot) || !$this->snapshotHashStillPublishable($metricId, $version, (string) ($snapshot['snapshot_hash'] ?? ''))) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function snapshotHashStillPublishable(string $metricId, string $version, string $hash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return false;
        }
        $metric = (new MetricCatalog($this->db))->active($metricId, $version);
        if (!is_array($metric)) {
            return false;
        }
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT quality_status,dimensions_json,cohort_size FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND snapshot_hash=%s AND state='published' LIMIT 1",
            $metricId, $version, $hash
        ), ARRAY_A);
        if (!is_array($row) || in_array((string) $row['quality_status'], ['suppressed','invalidated'], true)) {
            return false;
        }
        $dimensions = Json::object((string) ($row['dimensions_json'] ?? '{}'));
        $minimum = PrivacyQueryPolicy::effectiveMinimum(
            (array) $metric['definition'],
            $dimensions,
            max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20))
        );
        return PrivacyQueryPolicy::violations((array) $metric['definition'], $dimensions) === []
            && (int) $row['cohort_size'] >= $minimum;
    }

    /** @param array<string,mixed> $dimensions @return array<string,mixed>|null */
    private function latestSnapshot(string $metricId, string $version, array $dimensions): ?array
    {
        ksort($dimensions);
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND dimensions_hash=%s AND state='published' AND quality_status NOT IN ('suppressed','invalidated') ORDER BY window_end DESC,snapshot_revision DESC LIMIT 1",
            $metricId,
            $version,
            hash('sha256', Json::canonical($dimensions))
        ), ARRAY_A);
        if (!is_array($row)) return null;
        $metric=(new MetricCatalog($this->db))->active($metricId,$version);
        $minimum=is_array($metric)
            ? PrivacyQueryPolicy::effectiveMinimum((array)$metric['definition'],$dimensions,max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20)))
            : PHP_INT_MAX;
        if (!is_array($metric) || PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[] || (int)$row['cohort_size']<$minimum) return null;
        return [
            'snapshot_hash' => $row['snapshot_hash'],
            'value' => $row['value_decimal'] === null ? null : (float) $row['value_decimal'],
            'numerator' => $row['numerator_decimal'] === null ? null : (float) $row['numerator_decimal'],
            'denominator' => $row['denominator_decimal'] === null ? null : (float) $row['denominator_decimal'],
            'cohort_size' => (int) $row['cohort_size'],
            'quality_status' => $row['quality_status'],
            'window_start' => $row['window_start'],
            'window_end' => $row['window_end'],
            'data_through' => $row['data_through'],
            'uncertainty' => Json::object((string) ($row['uncertainty_json'] ?? '{}')),
            'caveats' => Json::list((string) ($row['caveats_json'] ?? '[]')),
        ];
    }

    /** @param array<string,mixed> $design @return array<int,string> */
    private function variantKeys(array $design): array
    {
        $out = [];
        foreach ((array) ($design['variants'] ?? []) as $variant) {
            $out[] = is_array($variant) ? (string) ($variant['key'] ?? '') : (string) $variant;
        }
        return array_values(array_filter($out, static fn(string $key): bool => $key !== ''));
    }

    /** @return array<string,int> */
    private function variantCounts(string $uuid): array
    {
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT variant_key,COUNT(*) AS total FROM `{$this->db->table('experiment_facts')}` WHERE experiment_uuid=%s GROUP BY variant_key ORDER BY variant_key", $uuid), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['variant_key']] = (int) $row['total'];
        }
        return $out;
    }
}
