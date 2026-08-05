<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Contracts\DatasetDefinitionValidator;
use Sabri\AnalyticsIntelligence\Contracts\EventSchemaValidator;
use Sabri\AnalyticsIntelligence\Contracts\ExperimentDefinitionValidator;
use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Domain\FilterEvaluator;
use Sabri\AnalyticsIntelligence\Domain\LifecyclePolicy;
use Sabri\AnalyticsIntelligence\Domain\Statistics;
use Sabri\AnalyticsIntelligence\Domain\TransformationEngine;
use Sabri\AnalyticsIntelligence\Infrastructure\CryptoBox;
use Sabri\AnalyticsIntelligence\Infrastructure\CsvSafe;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;

$tests = [];

$tests['event schema accepts governed safe contract'] = static function (): void {
    $schema = [
        'event_name' => 'learning.lesson_completed',
        'event_version' => '1.0.0',
        'owner_module' => 'file-05',
        'fact_semantics' => 'A learner completed an eligible lesson.',
        'purpose' => 'education_quality',
        'privacy_class' => 'C2',
        'retention_days' => 30,
        'deletion_key_field' => 'user_ref',
        'consumers' => ['cf-05'],
        'minor_policy' => 'aggregate_only',
        'late_window_seconds' => 86400,
        'fields' => [
            'lesson_ref' => ['type' => 'pseudonymous_ref'],
            'duration_seconds' => ['type' => 'integer', 'min' => 0, 'max' => 86400],
            'completed' => ['type' => 'boolean'],
        ],
    ];
    same([], (new EventSchemaValidator())->errors($schema));
};

$tests['event schema rejects sensitive fields and invalid privacy'] = static function (): void {
    $schema = [
        'event_name' => 'unsafe.event', 'event_version' => '1.0.0', 'owner_module' => 'file-00',
        'fact_semantics' => 'An unsafe test fact was emitted.', 'purpose' => 'test',
        'privacy_class' => 'C5', 'retention_days' => 500, 'deletion_key_field' => 'user_ref',
        'consumers' => ['cf-05'], 'fields' => ['otp_token' => ['type' => 'safe_string']],
    ];
    $errors = (new EventSchemaValidator())->errors($schema);
    truth(in_array('invalid_privacy_class', $errors, true));
    truth(in_array('forbidden_field_otp_token', $errors, true));
};

$tests['dataset definition validates lineage sources'] = static function (): void {
    $definition = [
        'dataset_id' => 'education.lesson_facts', 'dataset_version' => '1.0.0',
        'name' => 'Lesson facts', 'owner_module' => 'cf-05', 'grain' => 'lesson completion',
        'privacy_class' => 'C2', 'retention_days' => 180, 'region_code' => 'PK', 'provider_id' => 'local',
        'sources' => [['event_name' => 'learning.lesson_completed', 'event_version' => '1.0.0']],
        'fields' => [
            'lesson_ref' => ['type' => 'pseudonymous_ref', 'from' => 'properties.lesson_ref'],
            'completed' => ['type' => 'boolean', 'from' => 'properties.completed'],
        ],
    ];
    same([], (new DatasetDefinitionValidator())->errors($definition));
};

$tests['metric definition pins dataset and calculation'] = static function (): void {
    $definition = [
        'metric_id' => 'education.lesson_completion_rate', 'metric_version' => '1.0.0',
        'name' => 'Lesson completion rate', 'owner_module' => 'file-05',
        'business_question' => 'What share of eligible lesson starts complete?',
        'numerator' => 'completed_lessons', 'denominator' => 'eligible_lesson_starts',
        'grain' => 'day', 'window' => 'calendar_month', 'timezone' => 'Asia/Karachi',
        'dimensions' => ['course_ref', 'locale'], 'privacy_class' => 'C2', 'minimum_cohort' => 20,
        'source' => ['dataset_id' => 'education.lesson_facts', 'dataset_version' => '1.0.0'],
        'calculation' => ['type' => 'ratio', 'numerator_field' => 'completed', 'denominator_field' => 'eligible'],
    ];
    same([], (new MetricDefinitionValidator())->errors($definition));
    $definition['minimum_cohort'] = 1;
    truth(in_array('minimum_cohort_too_small', (new MetricDefinitionValidator())->errors($definition), true));
};

$tests['experiment definition requires metrics guardrails and enhanced review'] = static function (): void {
    $definition = [
        'name' => 'Course discovery test', 'hypothesis' => 'A clearer label improves eligible course discovery.',
        'assignment_owner' => 'file-26', 'audience' => ['adults_only' => true],
        'primary_metrics' => [['metric_id' => 'education.discovery_rate', 'metric_version' => '1.0.0', 'outcome_type' => 'ratio', 'base_dimensions' => []]],
        'guardrails' => [['metric_id' => 'safety.complaints', 'metric_version' => '1.0.0', 'outcome_type' => 'ratio', 'base_dimensions' => [], 'harm_direction' => 'increase', 'threshold' => 0.01]],
        'design' => ['variants' => ['control', 'treatment'], 'minimum_effect' => 0.01, 'minimum_sample' => 100, 'duration_days' => 14, 'multiple_testing_policy' => 'single_primary', 'missing_data_policy' => 'report_only'],
        'privacy_class' => 'C2', 'enhanced_review' => false,
    ];
    same([], (new ExperimentDefinitionValidator())->errors($definition));
};

$tests['lifecycle transitions are closed and independent'] = static function (): void {
    truth(LifecyclePolicy::allowed('event_schema', 'proposed', 'privacy_review'));
    falsity(LifecyclePolicy::allowed('event_schema', 'proposed', 'active'));
    truth(LifecyclePolicy::allowed('metric', 'approved', 'active'));
    truth(LifecyclePolicy::requiresIndependentActor('dataset', 'published'));
};

$tests['canonical JSON is deterministic'] = static function (): void {
    same('{"a":{"b":2,"c":3},"z":1}', Json::canonical(['z' => 1, 'a' => ['c' => 3, 'b' => 2]]));
};

$tests['CSV neutralizes spreadsheet formulas'] = static function (): void {
    $csv = CsvSafe::render([['label' => '=HYPERLINK("bad")', 'count' => 1]], ['label', 'count']);
    truth(str_contains($csv, "'=HYPERLINK"));
};

$tests['encryption is authenticated and context-bound'] = static function (): void {
    $box = new CryptoBox(str_repeat('k', 64));
    $cipher = $box->encrypt('private aggregate export', 'export-1');
    same('private aggregate export', $box->decrypt($cipher, 'export-1'));
    throws(static fn() => $box->decrypt($cipher, 'export-2'));
};

$tests['statistics expose uncertainty and practical significance'] = static function (): void {
    $interval = Statistics::proportionInterval(50, 100);
    truth($interval['lower'] < .5 && $interval['upper'] > .5);
    $comparison = Statistics::compareProportions(50, 100, 70, 100, .05);
    truth($comparison['conclusive']);
    truth($comparison['practical']);
};

$tests['transformation and filters are allowlist-driven'] = static function (): void {
    $event = ['properties' => ['score' => 7, 'status' => 'completed'], 'actor_ref' => 'abc'];
    $fields = [
        'score' => ['from' => 'properties.score', 'type' => 'integer'],
        'status' => ['from' => 'properties.status', 'type' => 'string'],
    ];
    $row = (new TransformationEngine())->map($event, $fields);
    same(['score' => 7, 'status' => 'completed'], $row);
    truth(FilterEvaluator::matches($row, [['field' => 'status', 'operator' => 'eq', 'value' => 'completed']]));
    falsity(FilterEvaluator::matches($row, [['field' => 'score', 'operator' => 'gt', 'value' => 9]]));
};


$tests['sensitive detector blocks nested secrets and identifiers'] = static function (): void {
    $detector = new SensitiveValueDetector();
    truth(in_array('forbidden_key_fragment', $detector->violations(['nested' => ['api_key' => 'hidden']]), true));
    truth(in_array('email_address', $detector->violations('person@example.com'), true));
    truth(in_array('payment_card_number', $detector->violations('4111 1111 1111 1111'), true));
    same([], $detector->violations(['status' => 'completed', 'count' => 22]));
};

$tests['metric semantics and filter contracts are closed'] = static function (): void {
    $definition = [
        'metric_id' => 'education.safe_rate', 'metric_version' => '1.0.0', 'name' => 'Safe rate',
        'owner_module' => 'cf-05', 'business_question' => 'What proportion completes safely?',
        'numerator' => 'completed', 'denominator' => 'eligible', 'grain' => 'day', 'window' => 'month',
        'timezone' => 'Asia/Karachi', 'dimensions' => ['locale'], 'privacy_class' => 'C2', 'minimum_cohort' => 20,
        'source' => ['dataset_id' => 'education.lesson_facts', 'dataset_version' => '1.0.0'],
        'calculation' => ['type' => 'ratio', 'numerator_filters' => [['field' => 'completed', 'operator' => 'eq', 'value' => true]], 'denominator_filters' => [['field' => 'eligible', 'operator' => 'eq', 'value' => true]]],
        'historical_semantics' => 'current', 'freshness_seconds' => 3600,
    ];
    same([], (new MetricDefinitionValidator())->errors($definition));
    $definition['calculation']['numerator_filters'][0]['operator'] = 'sql';
    truth(in_array('invalid_numerator_filters_filter', (new MetricDefinitionValidator())->errors($definition), true));
};

$tests['experiment variants cannot be duplicated'] = static function (): void {
    $definition = [
        'name' => 'Duplicate variant test', 'hypothesis' => 'A test hypothesis long enough.', 'assignment_owner' => 'file-26',
        'audience' => ['adults_only' => true],
        'primary_metrics' => [['metric_id' => 'education.discovery_rate', 'metric_version' => '1.0.0', 'outcome_type' => 'ratio', 'base_dimensions' => []]],
        'guardrails' => [['metric_id' => 'safety.complaints', 'metric_version' => '1.0.0', 'outcome_type' => 'ratio', 'base_dimensions' => [], 'harm_direction' => 'increase', 'threshold' => 0.01]],
        'design' => ['variants' => ['control', 'control'], 'minimum_effect' => 0.01, 'minimum_sample' => 100, 'duration_days' => 14, 'multiple_testing_policy' => 'single_primary', 'missing_data_policy' => 'report_only'],
        'privacy_class' => 'C2', 'enhanced_review' => false,
    ];
    truth(in_array('duplicate_variant', (new ExperimentDefinitionValidator())->errors($definition), true));
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}
if ($failures > 0) { exit(1); }
fwrite(STDOUT, 'All ' . count($tests) . " executable tests passed.\n");

function truth(bool $condition): void { if (!$condition) { throw new RuntimeException('Expected true.'); } }
function falsity(bool $condition): void { if ($condition) { throw new RuntimeException('Expected false.'); } }
function same(mixed $expected, mixed $actual): void { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true)); } }
function throws(callable $callback): void { try { $callback(); } catch (Throwable) { return; } throw new RuntimeException('Expected exception.'); }
