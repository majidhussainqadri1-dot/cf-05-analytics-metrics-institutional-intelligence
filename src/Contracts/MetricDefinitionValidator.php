<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class MetricDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        foreach (['metric_id','metric_version','name','owner_module','business_question','numerator','denominator','grain','window','timezone','dimensions','privacy_class','minimum_cohort','source','calculation'] as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = 'missing_' . $key;
            }
        }
        if (isset($definition['metric_id']) && preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $definition['metric_id']) !== 1) {
            $errors[] = 'invalid_metric_id';
        }
        if (isset($definition['metric_version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $definition['metric_version']) !== 1) {
            $errors[] = 'invalid_metric_version';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['owner_module'] ?? '')) !== 1) {
            $errors[] = 'invalid_owner_module';
        }
        if (strlen(trim((string) ($definition['name'] ?? ''))) < 3 || strlen((string) ($definition['name'] ?? '')) > 190) {
            $errors[] = 'invalid_name';
        }
        if (strlen(trim((string) ($definition['business_question'] ?? ''))) < 8 || strlen((string) ($definition['business_question'] ?? '')) > 2000) {
            $errors[] = 'invalid_business_question';
        }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $minimumCohort = (int) ($definition['minimum_cohort'] ?? 0);
        if ($minimumCohort < 5 || $minimumCohort > 1000000) {
            $errors[] = 'minimum_cohort_too_small';
        }
        try {
            new \DateTimeZone((string) ($definition['timezone'] ?? ''));
        } catch (\Throwable) {
            $errors[] = 'invalid_timezone';
        }

        $dimensions = is_array($definition['dimensions'] ?? null) ? array_values($definition['dimensions']) : [];
        if (!is_array($definition['dimensions'] ?? null) || count($dimensions) > 20 || count(array_unique(array_map('strval', $dimensions))) !== count($dimensions)) {
            $errors[] = 'invalid_dimensions';
        } else {
            foreach ($dimensions as $dimension) {
                if (!is_string($dimension) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dimension) !== 1) {
                    $errors[] = 'invalid_dimension';
                }
            }
        }
        $errors = array_merge($errors, $this->dimensionPolicyErrors($definition, array_map('strval', $dimensions), $minimumCohort));

        $source = $definition['source'] ?? null;
        if (!is_array($source)
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($source['dataset_id'] ?? '')) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($source['dataset_version'] ?? '')) !== 1) {
            $errors[] = 'invalid_source';
        }

        $calculation = $definition['calculation'] ?? null;
        $type = is_array($calculation) ? (string) ($calculation['type'] ?? '') : '';
        if (!is_array($calculation) || !in_array($type, ['count','sum','average','ratio'], true)) {
            $errors[] = 'invalid_calculation';
        } elseif (in_array($type, ['sum','average'], true) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($calculation['field'] ?? '')) !== 1) {
            $errors[] = 'invalid_calculation_field';
        } elseif ($type === 'ratio') {
            foreach (['numerator_filters','denominator_filters'] as $key) {
                if (isset($calculation[$key]) && !is_array($calculation[$key])) {
                    $errors[] = 'invalid_' . $key;
                } elseif (is_array($calculation[$key] ?? null)) {
                    $errors = array_merge($errors, $this->filterErrors($calculation[$key], $key));
                }
            }
        } elseif (is_array($calculation['filters'] ?? null)) {
            $errors = array_merge($errors, $this->filterErrors($calculation['filters'], 'filters'));
        }
        if (isset($definition['cohort_field']) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $definition['cohort_field']) !== 1) {
            $errors[] = 'invalid_cohort_field';
        }
        if (isset($definition['historical_semantics']) && !in_array((string) $definition['historical_semantics'], ['current','as_occurred'], true)) {
            $errors[] = 'invalid_historical_semantics';
        }
        if (isset($definition['freshness_seconds']) && ((int) $definition['freshness_seconds'] < 60 || (int) $definition['freshness_seconds'] > 366 * 86400)) {
            $errors[] = 'invalid_freshness_seconds';
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
            $policies[$dimension] = [
                'sensitivity' => (string) ($policy['sensitivity'] ?? 'standard'),
                'minimum_cohort' => max($minimum, (int) ($policy['minimum_cohort'] ?? $minimum)),
                'max_cardinality' => max(1, min(1000000, (int) ($policy['max_cardinality'] ?? 10000))),
            ];
        }
        ksort($policies);
        $definition['dimension_policies'] = $policies;
        $definition['prohibited_dimension_combinations'] = array_values($definition['prohibited_dimension_combinations'] ?? []);
        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $definition['privacy_controls'] = [
            'max_dimensions_per_query' => max(1, min(10, (int) ($controls['max_dimensions_per_query'] ?? 4))),
            'max_distinct_slices_per_hour' => max(5, min(200, (int) ($controls['max_distinct_slices_per_hour'] ?? 30))),
            'max_privacy_budget_per_hour' => max(10, min(1000, (int) ($controls['max_privacy_budget_per_hour'] ?? 80))),
            'differencing_floor' => max($minimum, min(1000000, (int) ($controls['differencing_floor'] ?? $minimum))),
        ];
        $definition['historical_semantics'] = (string) ($definition['historical_semantics'] ?? 'current');
        $definition['freshness_seconds'] = max(60, min(366 * 86400, (int) ($definition['freshness_seconds'] ?? 86400)));
        if (is_array($definition['calculation'] ?? null)) {
            foreach (['filters','numerator_filters','denominator_filters'] as $key) {
                if (is_array($definition['calculation'][$key] ?? null)) {
                    $definition['calculation'][$key] = array_values($definition['calculation'][$key]);
                }
            }
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
            $policies = [];
            foreach ($dimensions as $dimension) {
                $policies[$dimension] = [
                    'sensitivity' => 'standard',
                    'minimum_cohort' => max(5, $minimumCohort),
                    'max_cardinality' => 10000,
                ];
            }
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
            if (!in_array((string) ($policy['sensitivity'] ?? ''), ['public','standard','sensitive','high'], true)) {
                $errors[] = 'invalid_dimension_sensitivity';
            }
            $policyMinimum = (int) ($policy['minimum_cohort'] ?? 0);
            if ($policyMinimum < max(5, $minimumCohort) || $policyMinimum > 1000000) {
                $errors[] = 'invalid_dimension_minimum_cohort';
            }
            $cardinality = (int) ($policy['max_cardinality'] ?? 0);
            if ($cardinality < 1 || $cardinality > 1000000) {
                $errors[] = 'invalid_dimension_cardinality';
            }
        }
        foreach ((array) ($definition['prohibited_dimension_combinations'] ?? []) as $combination) {
            if (!is_array($combination) || count($combination) < 2 || count($combination) > 10) {
                $errors[] = 'invalid_prohibited_dimension_combination';
                continue;
            }
            $combination = array_values(array_unique(array_map('strval', $combination)));
            if (count($combination) < 2 || array_diff($combination, $dimensions) !== []) {
                $errors[] = 'invalid_prohibited_dimension_combination';
            }
        }
        $controls = $definition['privacy_controls'] ?? null;
        if (!is_array($controls)) {
            $controls = [
                'max_dimensions_per_query' => 4,
                'max_distinct_slices_per_hour' => 30,
                'max_privacy_budget_per_hour' => 80,
                'differencing_floor' => max(5, $minimumCohort),
            ];
        }
        if ((int) ($controls['max_dimensions_per_query'] ?? 0) < 1 || (int) ($controls['max_dimensions_per_query'] ?? 0) > 10) {
            $errors[] = 'invalid_max_dimensions_per_query';
        }
        if ((int) ($controls['max_distinct_slices_per_hour'] ?? 0) < 5 || (int) ($controls['max_distinct_slices_per_hour'] ?? 0) > 200) {
            $errors[] = 'invalid_max_distinct_slices';
        }
        if ((int) ($controls['max_privacy_budget_per_hour'] ?? 0) < 10 || (int) ($controls['max_privacy_budget_per_hour'] ?? 0) > 1000) {
            $errors[] = 'invalid_privacy_budget';
        }
        if ((int) ($controls['differencing_floor'] ?? 0) < max(5, $minimumCohort) || (int) ($controls['differencing_floor'] ?? 0) > 1000000) {
            $errors[] = 'invalid_differencing_floor';
        }
        return $errors;
    }

    /** @param array<int,mixed> $filters @return array<int,string> */
    private function filterErrors(array $filters, string $context): array
    {
        $errors = [];
        if (count($filters) > 50) {
            return ['too_many_' . $context];
        }
        foreach ($filters as $filter) {
            if (!is_array($filter)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($filter['field'] ?? '')) !== 1
                || !in_array((string) ($filter['operator'] ?? ''), ['eq','neq','in','not_in','gt','gte','lt','lte','exists'], true)) {
                $errors[] = 'invalid_' . $context . '_filter';
            }
        }
        return $errors;
    }
}
