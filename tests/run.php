<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Contracts\EventSchemaValidator;
use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Domain\LifecyclePolicy;
use Sabri\AnalyticsIntelligence\Domain\PrivacyGateway;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;

$tests = [];
$tests['event schema accepts safe fields'] = static function (): void {
    $schema = [
        'event_name' => 'learning.lesson_completed',
        'event_version' => '1.0.0',
        'owner_module' => 'file-05',
        'purpose' => 'education_quality',
        'privacy_class' => 'C2',
        'retention_days' => 30,
        'deletion_key_field' => 'user_ref',
        'fields' => [
            'lesson_ref' => ['type' => 'pseudonymous_ref'],
            'duration_seconds' => ['type' => 'integer', 'min' => 0, 'max' => 86400],
            'completed' => ['type' => 'boolean'],
        ],
    ];
    assertSame([], (new EventSchemaValidator())->errors($schema));
};

$tests['event schema rejects secrets'] = static function (): void {
    $schema = [
        'event_name' => 'unsafe.event',
        'event_version' => '1.0.0',
        'owner_module' => 'file-00',
        'purpose' => 'test',
        'privacy_class' => 'C3',
        'retention_days' => 30,
        'deletion_key_field' => 'user_ref',
        'fields' => ['otp_token' => ['type' => 'safe_string']],
    ];
    $errors = (new EventSchemaValidator())->errors($schema);
    assertTrue(in_array('forbidden_field_otp_token', $errors, true));
};

$tests['privacy gateway allowlists and pseudonymizes'] = static function (): void {
    $schema = [
        'fields' => [
            'duration_seconds' => ['type' => 'integer', 'min' => 0, 'max' => 1000],
            'status' => ['type' => 'enum', 'values' => ['started','completed']],
        ],
        'requires_consent' => false,
        'minor_policy' => 'aggregate_only',
    ];
    $payload = [
        'actor_ref' => 'user-123',
        'object_ref' => 'lesson-5',
        'deletion_key' => 'user-123',
        'properties' => [
            'duration_seconds' => 99,
            'status' => 'completed',
            'password' => 'must disappear',
        ],
    ];
    $result = (new PrivacyGateway(str_repeat('k', 64)))->process($payload, $schema);
    assertTrue($result['accepted']);
    assertSame(['duration_seconds' => 99, 'status' => 'completed'], $result['properties']);
    assertTrue(strlen((string) $result['actor_ref']) === 64);
    assertTrue(!str_contains(json_encode($result), 'must disappear'));
};

$tests['privacy gateway fails consent and range'] = static function (): void {
    $schema = [
        'fields' => ['score' => ['type' => 'integer', 'min' => 0, 'max' => 10]],
        'requires_consent' => true,
        'minor_policy' => 'deny',
    ];
    $payload = [
        'consent_granted' => false,
        'is_minor' => true,
        'properties' => ['score' => 99],
    ];
    $result = (new PrivacyGateway(str_repeat('z', 64)))->process($payload, $schema);
    assertTrue(!$result['accepted']);
    assertTrue(in_array('consent_required', $result['errors'], true));
    assertTrue(in_array('minor_event_denied', $result['errors'], true));
    assertTrue(in_array('out_of_range_score', $result['errors'], true));
};

$tests['sensitive detector rejects credentials and card values'] = static function (): void {
    $detector = new SensitiveValueDetector();
    assertTrue(in_array('email_address', $detector->violations('patient@example.com'), true));
    assertTrue(in_array('payment_card_number', $detector->violations('4111 1111 1111 1111'), true));
    assertTrue(in_array('credential_assignment', $detector->violations('api_key=super-secret-value'), true));
    assertSame([], $detector->violations('completed'));
};

$tests['privacy gateway rejects sensitive safe string values'] = static function (): void {
    $schema = [
        'fields' => ['label' => ['type' => 'safe_string', 'max_length' => 100]],
        'requires_consent' => false,
        'minor_policy' => 'aggregate_only',
    ];
    $result = (new PrivacyGateway(str_repeat('p', 64)))->process(['properties' => ['label' => 'person@example.com']], $schema);
    assertTrue(!$result['accepted']);
    assertTrue(in_array('sensitive_value_label', $result['errors'], true));
};

$tests['catalog lifecycle policies are closed and versioned'] = static function (): void {
    assertTrue(LifecyclePolicy::allowed('event_schema', 'proposed', 'privacy_review'));
    assertTrue(!LifecyclePolicy::allowed('event_schema', 'proposed', 'active'));
    assertTrue(LifecyclePolicy::allowed('metric', 'approved', 'active'));
    assertTrue(!LifecyclePolicy::allowed('metric', 'draft', 'active'));
    assertTrue(LifecyclePolicy::requiresIndependentActor('metric', 'approved'));
};

$tests['metric requires safe cohort'] = static function (): void {
    $definition = [
        'metric_id' => 'education.lesson_completion_rate',
        'metric_version' => '1.0.0',
        'name' => 'Lesson completion rate',
        'owner_module' => 'file-05',
        'business_question' => 'What share of eligible starts complete?',
        'numerator' => 'completed_lessons',
        'denominator' => 'eligible_lesson_starts',
        'grain' => 'day',
        'window' => 'calendar_month',
        'timezone' => 'Asia/Karachi',
        'dimensions' => ['course_ref','locale'],
        'privacy_class' => 'C2',
        'minimum_cohort' => 20,
    ];
    assertSame([], (new MetricDefinitionValidator())->errors($definition));
    $definition['minimum_cohort'] = 1;
    assertTrue(in_array('minimum_cohort_too_small', (new MetricDefinitionValidator())->errors($definition), true));
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

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, 'All ' . count($tests) . " tests passed.\n");

function assertTrue(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed.');
    }
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true));
    }
}
