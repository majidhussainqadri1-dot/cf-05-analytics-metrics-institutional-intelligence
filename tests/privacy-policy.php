<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Sabri\AnalyticsIntelligence\Contracts\MetricDefinitionValidator;
use Sabri\AnalyticsIntelligence\Domain\PrivacyQueryPolicy;

$tests = [];
$tests['metric normalization materializes dimension privacy policy'] = static function (): void {
    $definition = baseDefinition();
    $normalized = (new MetricDefinitionValidator())->normalize($definition);
    same('standard', $normalized['dimension_policies']['locale']['sensitivity']);
    same(20, $normalized['dimension_policies']['locale']['minimum_cohort']);
    same(4, $normalized['privacy_controls']['max_dimensions_per_query']);
    same([], (new MetricDefinitionValidator())->errors($normalized));
};

$tests['dimension-specific minimum is enforced'] = static function (): void {
    $definition = baseDefinition();
    $definition['dimension_policies'] = [
        'locale' => ['sensitivity' => 'standard', 'minimum_cohort' => 20, 'max_cardinality' => 100],
        'region' => ['sensitivity' => 'high', 'minimum_cohort' => 100, 'max_cardinality' => 50],
    ];
    same(100, PrivacyQueryPolicy::effectiveMinimum($definition, ['region' => 'PK'], 20));
};

$tests['prohibited dimension combination is blocked'] = static function (): void {
    $definition = baseDefinition();
    $definition['dimension_policies'] = [
        'locale' => ['sensitivity' => 'standard', 'minimum_cohort' => 20, 'max_cardinality' => 100],
        'region' => ['sensitivity' => 'sensitive', 'minimum_cohort' => 40, 'max_cardinality' => 50],
    ];
    $definition['prohibited_dimension_combinations'] = [['locale','region']];
    $violations = PrivacyQueryPolicy::violations($definition, ['locale' => 'ur', 'region' => 'PK']);
    truth(in_array('prohibited_dimension_combination', $violations, true));
};

$tests['nested low-difference slices are detected'] = static function (): void {
    $current = [
        'window_start' => '2026-08-01 00:00:00',
        'window_end' => '2026-08-02 00:00:00',
        'dimension_names' => ['locale','region'],
        'dimension_hashes' => ['locale' => 'a', 'region' => 'b'],
        'cohort_size' => 42,
    ];
    $previous = [
        'window_start' => '2026-08-01 00:00:00',
        'window_end' => '2026-08-02 00:00:00',
        'dimension_names' => ['locale'],
        'dimension_hashes' => ['locale' => 'a'],
        'cohort_size' => 50,
    ];
    truth(PrivacyQueryPolicy::differencingRisk($current, $previous, 20));
    falsity(PrivacyQueryPolicy::differencingRisk($current, $previous, 5));
};

$tests['exact repeated slice is not treated as differencing'] = static function (): void {
    $slice = [
        'window_start' => '2026-08-01 00:00:00',
        'window_end' => '2026-08-02 00:00:00',
        'dimension_names' => ['locale'],
        'dimension_hashes' => ['locale' => 'a'],
        'cohort_size' => 50,
    ];
    falsity(PrivacyQueryPolicy::differencingRisk($slice, $slice, 20));
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
fwrite(STDOUT, 'All ' . count($tests) . " privacy-policy tests passed.\n");

function baseDefinition(): array
{
    return [
        'metric_id' => 'education.safe_rate', 'metric_version' => '1.0.0', 'name' => 'Safe rate',
        'owner_module' => 'cf-05', 'business_question' => 'What proportion completes safely?',
        'numerator' => 'completed', 'denominator' => 'eligible', 'grain' => 'day', 'window' => 'month',
        'timezone' => 'Asia/Karachi', 'dimensions' => ['locale','region'], 'privacy_class' => 'C2', 'minimum_cohort' => 20,
        'source' => ['dataset_id' => 'education.lesson_facts', 'dataset_version' => '1.0.0'],
        'calculation' => ['type' => 'ratio', 'numerator_filters' => [['field' => 'completed', 'operator' => 'eq', 'value' => true]], 'denominator_filters' => [['field' => 'eligible', 'operator' => 'eq', 'value' => true]]],
    ];
}
function truth(bool $condition): void { if (!$condition) { throw new RuntimeException('Expected true.'); } }
function falsity(bool $condition): void { if ($condition) { throw new RuntimeException('Expected false.'); } }
function same(mixed $expected, mixed $actual): void { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true)); } }
