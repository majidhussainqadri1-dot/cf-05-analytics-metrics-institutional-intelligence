<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class MetricDefinitionValidator
{
    private const MAX_COHORT = 1000000;
    private const FILTER_OPERATORS = ['eq','neq','in','not_in','gt','gte','lt','lte','exists','not_exists'];

    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        $required = [
            'metric_id','metric_version','name','owner_module','business_question','numerator',
            'denominator','grain','window','timezone','dimensions','privacy_class',
            'minimum_cohort','source','calculation',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = 'missing_' . $key;
            }
        }
        $allowed = array_merge($required, [
            'dimension_policies','prohibited_dimension_combinations','privacy_controls',
            'cohort_field','historical_semantics','freshness_seconds','description',
            'exclusions','attribution','rounding','unit','owner_contact',
        ]);
        foreach (array_keys($definition) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $errors[] = 'unknown_metric_key';
            }
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($definition['metric_id'] ?? '')) !== 1) {
            $errors[] = 'invalid_metric_id';
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($definition['metric_version'] ?? '')) !== 1) {
            $errors[] = 'invalid_metric_version';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['owner_module'] ?? '')) !== 1) {
            $errors[] = 'invalid_owner_module';
        }
        $this->textLength($definition['name'] ?? null, 3, 190, 'invalid_name', $errors);
        $this->textLength($definition['business_question'] ?? null, 8, 2000, 'invalid_business_question', $errors);
        $this->textLength($definition['numerator'] ?? null, 1, 2000, 'invalid_numerator', $errors);
        $this->textLength($definition['denominator'] ?? null, 1, 2000, 'invalid_denominator', $errors);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', (string) ($definition['grain'] ?? '')) !== 1) {
            $errors[] = 'invalid_grain';
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', (string) ($definition['window'] ?? '')) !== 1) {
            $errors[] = 'invalid_window';
        }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $minimumCohort = filter_var($definition['minimum_cohort'] ?? null, FILTER_VALIDATE_INT);
        if ($minimumCohort === false || $minimumCohort < 5 || $minimumCohort > self::MAX_COHORT) {
            $errors[] = 'invalid_minimum_cohort';
            $minimumCohort = 5;
        }
        try {
            new \DateTimeZone((string) ($definition['timezone'] ?? ''));
        } catch (\Throwable) {
            $errors[] = 'invalid_timezone';
        }

        $dimensions = is_array($definition['dimensions'] ?? null) ? array_values($definition['dimensions']) : [];
        if (!is_array($definition['dimensions'] ?? null) || count($dimensions) > 20) {
            $errors[] = 'invalid_dimensions';
        } else {
            $seen = [];
            foreach ($dimensions as $dimension) {
                if (!is_string($dimension) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dimension) !== 1) {
                    $errors[] = 'invalid_dimension';
                    continue;
                }
                if (isset($seen[$dimension])) {
                    $errors[] = 'duplicate_dimension';
                }
                $seen[$dimension] = true;
            }
        }
        $dimensionNames = array_values(array_filter($dimensions, 'is_string'));
        $errors = array_merge($errors, $this->dimensionPolicyErrors($definition, $dimensionNames, (int) $minimumCohort));

        $source = $definition['source'] ?? null;
        if (!is_array($source)
            || array_diff(array_keys($source), ['dataset_id','dataset_version']) !== []
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($source['dataset_id'] ?? '')) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($source['dataset_version'] ?? '')) !== 1) {
            $errors[] = 'invalid_source';
        }

        $calculation = $definition['calculation'] ?? null;
        $type = is_array($calculation) ? (string) ($calculation['type'] ?? '') : '';
        if (!is_array($calculation) || !in_array($type, ['count','sum','average','ratio'], true)) {
            $errors[] = 'invalid_calculation';
        } else {
            $allowedCalculationKeys = match ($type) {
                'count' => ['type','filters'],
                'sum','average' => ['type','field','filters'],
                'ratio' => ['type','numerator_filters','denominator_filters'],
                default => ['type'],
            };
            if (array_diff(array_keys($calculation), $allowedCalculationKeys) !== []) {
                $errors[] = 'unknown_calculation_key';
            }
            if (in_array($type, ['sum','average'], true)
                && preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($calculation['field'] ?? '')) !== 1) {
                $errors[] = 'invalid_calculation_field';
            }
            if ($type === 'ratio') {
                foreach (['numerator_filters','denominator_filters'] as $key) {
                    if (!is_array($calculation[$key] ?? null)) {
                        $errors[] = 'invalid_' . $key;
                    } else {
                        $errors = array_merge($errors, $this->filterErrors($calculation[$key], $key));
                    }
                }
            } else {
                if (isset($calculation['filters']) && !is_array($calculation['filters'])) {
                    $errors[] = 'invalid_filters';
                } elseif (is_array($calculation['filters'] ?? null)) {
                    $errors = array_merge($errors, $this->filterErrors($calculation['filters'], 'filters'));
                }
            }
        }

        if (isset($definition['cohort_field']) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $definition['cohort_field']) !== 1) {
            $errors[] = 'invalid_cohort_field';
        }
        if (isset($definition['historical_semantics']) && !in_array((string) $definition['historical_semantics'], ['current','as_occurred'], true)) {
            $errors[] = 'invalid_historical_semantics';
        }
        if (isset($definition['freshness_seconds'])) {
            $freshness = filter_var($definition['freshness_seconds'], FILTER_VALIDATE_INT);
            if ($freshness === false || $freshness < 60 || $freshness > 366 * DAY_IN_SECONDS) {
                $errors[] = 'invalid_freshness_seconds';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $definition */
    public function normalize(array $definition): array
    {
        if (isset($definition['dimensions']) && is_array($definition['dimensions'])) {
            $definition['dimensions'] = array_values(array_unique(array_map('strval', $definition['dimensions'])));
            sort($definition['dimensions']);
        }
        $minimum = max(5, (int) ($definition['minimum_cohort'] ?? 20));
        $policies = is_array($definition['dimension_policies'] ?? null) ? $definition['dimension_policies'] : [];
        foreach ((array) ($definition['dimensions'] ?? []) as $dimension) {
            $policy = is_array($policies[$dimension] ?? null) ? $policies[$dimension] : [];
            $sensitivity = (string) ($policy['sensitivity'] ?? 'standard');
            $floor = match ($sensitivity) {
                'sensitive' => max($minimum, 20),
                'high' => max($minimum, 50),
                default => $minimum,
            };
            $policies[$dimension] = [
                'sensitivity' => $sensitivity,
                'minimum_cohort' => max($floor, (int) ($policy['minimum_cohort'] ?? $floor)),
                'max_cardinality' => max(1, min(self::MAX_COHORT, (int) ($policy['max_cardinality'] ?? 10000))),
            ];
        }
        ksort($policies);
        $definition['dimension_policies'] = $policies;

        $combinations = [];
        foreach ((array) ($definition['prohibited_dimension_combinations'] ?? []) as $combination) {
            if (!is_array($combination)) {
                continue;
            }
            $combination = array_values(array_unique(array_map('strval', $combination)));
            sort($combination);
            $combinations[implode('|', $combination)] = $combination;
        }
        ksort($combinations);
        $definition['prohibited_dimension_combinations'] = array_values($combinations);

        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $definition['privacy_controls'] = [
            'max_dimensions_per_query' => max(1, min(10, (int) ($controls['max_dimensions_per_query'] ?? 4))),
            'max_distinct_slices_per_hour' => max(5, min(200, (int) ($controls['max_distinct_slices_per_hour'] ?? 30))),
            'max_privacy_budget_per_hour' => max(10, min(1000, (int) ($controls['max_privacy_budget_per_hour'] ?? 80))),
            'differencing_floor' => max($minimum, min(self::MAX_COHORT, (int) ($controls['differencing_floor'] ?? $minimum))),
        ];
        $definition['historical_semantics'] = (string) ($definition['historical_semantics'] ?? 'current');
        $definition['freshness_seconds'] = max(60, min(366 * DAY_IN_SECONDS, (int) ($definition['freshness_seconds'] ?? DAY_IN_SECONDS)));
        if (is_array($definition['calculation'] ?? null)) {
            foreach (['filters','numerator_filters','denominator_filters'] as $key) {
                if (is_array($definition['calculation'][$key] ?? null)) {
                    $definition['calculation'][$key] = $this->normalizeFilters($definition['calculation'][$key]);
                }
            }
            ksort($definition['calculation']);
        }
        if (is_array($definition['source'] ?? null)) {
            ksort($definition['source']);
        }
        ksort($definition);
        return $definition;
    }

    /** @param array<string,mixed> $definition @param array<int,string> $dimensions @return array<int,string> */
    private function dimensionPolicyErrors(array $definition, array $dimensions, int $minimumCohort): array
    {
        $errors = [];
        $policies = $definition['dimension_policies'] ?? null;
        if (!is_array($policies)) {
            $errors[] = 'missing_dimension_policies';
            $policies = [];
        }
        if (array_diff(array_keys($policies), $dimensions) !== []) {
            $errors[] = 'unknown_dimension_policy';
        }
        foreach ($dimensions as $dimension) {
            $policy = $policies[$dimension] ?? null;
            if (!is_array($policy)) {
                $errors[] = 'missing_dimension_policy_' . $dimension;
                continue;
            }
            if (array_diff(array_keys($policy), ['sensitivity','minimum_cohort','max_cardinality']) !== []) {
                $errors[] = 'unknown_dimension_policy_option';
            }
            $sensitivity = (string) ($policy['sensitivity'] ?? '');
            if (!in_array($sensitivity, ['public','standard','sensitive','high'], true)) {
                $errors[] = 'invalid_dimension_sensitivity';
                $sensitivity = 'high';
            }
            $floor = match ($sensitivity) {
                'sensitive' => max(20, $minimumCohort),
                'high' => max(50, $minimumCohort),
                default => max(5, $minimumCohort),
            };
            $policyMinimum = filter_var($policy['minimum_cohort'] ?? null, FILTER_VALIDATE_INT);
            if ($policyMinimum === false || $policyMinimum < $floor || $policyMinimum > self::MAX_COHORT) {
                $errors[] = 'invalid_dimension_minimum_cohort';
            }
            $cardinality = filter_var($policy['max_cardinality'] ?? null, FILTER_VALIDATE_INT);
            if ($cardinality === false || $cardinality < 1 || $cardinality > self::MAX_COHORT) {
                $errors[] = 'invalid_dimension_cardinality';
            }
        }

        $combinationKeys = [];
        foreach ((array) ($definition['prohibited_dimension_combinations'] ?? []) as $combination) {
            if (!is_array($combination) || count($combination) < 2 || count($combination) > 10) {
                $errors[] = 'invalid_prohibited_dimension_combination';
                continue;
            }
            $combination = array_values(array_unique(array_map('strval', $combination)));
            sort($combination);
            if (count($combination) < 2 || array_diff($combination, $dimensions) !== []) {
                $errors[] = 'invalid_prohibited_dimension_combination';
                continue;
            }
            $key = implode('|', $combination);
            if (isset($combinationKeys[$key])) {
                $errors[] = 'duplicate_prohibited_dimension_combination';
            }
            $combinationKeys[$key] = true;
        }

        $controls = $definition['privacy_controls'] ?? null;
        if (!is_array($controls)) {
            return array_merge($errors, ['missing_privacy_controls']);
        }
        if (array_diff(array_keys($controls), ['max_dimensions_per_query','max_distinct_slices_per_hour','max_privacy_budget_per_hour','differencing_floor']) !== []) {
            $errors[] = 'unknown_privacy_control';
        }
        $ranges = [
            'max_dimensions_per_query' => [1, 10],
            'max_distinct_slices_per_hour' => [5, 200],
            'max_privacy_budget_per_hour' => [10, 1000],
            'differencing_floor' => [max(5, $minimumCohort), self::MAX_COHORT],
        ];
        foreach ($ranges as $key => [$minimum, $maximum]) {
            $value = filter_var($controls[$key] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < $minimum || $value > $maximum) {
                $errors[] = 'invalid_' . $key;
            }
        }
        return $errors;
    }

    /** @param array<int,mixed> $filters @return array<int,string> */
    private function filterErrors(array $filters, string $context): array
    {
        if (count($filters) > 50) {
            return ['too_many_' . $context];
        }
        $errors = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)
                || array_diff(array_keys($filter), ['field','operator','value']) !== []
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($filter['field'] ?? '')) !== 1
                || !in_array((string) ($filter['operator'] ?? ''), self::FILTER_OPERATORS, true)) {
                $errors[] = 'invalid_' . $context . '_filter';
                continue;
            }
            $operator = (string) $filter['operator'];
            $hasValue = array_key_exists('value', $filter);
            if (in_array($operator, ['exists','not_exists'], true) && $hasValue) {
                $errors[] = 'unexpected_' . $context . '_filter_value';
            } elseif (in_array($operator, ['in','not_in'], true)) {
                if (!$hasValue || !is_array($filter['value']) || $filter['value'] === [] || count($filter['value']) > 100) {
                    $errors[] = 'invalid_' . $context . '_filter_values';
                } else {
                    foreach ($filter['value'] as $value) {
                        if (!$this->safeFilterScalar($value)) {
                            $errors[] = 'invalid_' . $context . '_filter_value';
                        }
                    }
                }
            } elseif (!in_array($operator, ['exists','not_exists'], true) && (!$hasValue || !$this->safeFilterScalar($filter['value']))) {
                $errors[] = 'invalid_' . $context . '_filter_value';
            }
        }
        return $errors;
    }

    private function safeFilterScalar(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            return false;
        }
        if (is_float($value) && !is_finite($value)) {
            return false;
        }
        return !is_string($value) || strlen($value) <= 190;
    }

    /** @param array<int,mixed> $filters @return array<int,array<string,mixed>> */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            if (is_array($filter['value'] ?? null)) {
                usort($filter['value'], static fn (mixed $a, mixed $b): int => strcmp(json_encode($a), json_encode($b)));
            }
            ksort($filter);
            $normalized[] = $filter;
        }
        usort($normalized, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        return $normalized;
    }

    /** @param array<int,string> $errors */
    private function textLength(mixed $value, int $minimum, int $maximum, string $code, array &$errors): void
    {
        if (!is_string($value)) {
            $errors[] = $code;
            return;
        }
        $length = strlen(trim($value));
        if ($length < $minimum || $length > $maximum) {
            $errors[] = $code;
        }
    }
}
