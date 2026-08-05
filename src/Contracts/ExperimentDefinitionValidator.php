<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class ExperimentDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        foreach (['name','hypothesis','assignment_owner','audience','primary_metrics','guardrails','design','privacy_class'] as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = 'missing_' . $key;
            }
        }
        if (strlen(trim((string) ($definition['name'] ?? ''))) < 3) {
            $errors[] = 'name_too_short';
        }
        if (strlen(trim((string) ($definition['hypothesis'] ?? ''))) < 20) {
            $errors[] = 'hypothesis_too_short';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['assignment_owner'] ?? '')) !== 1) {
            $errors[] = 'invalid_assignment_owner';
        }
        if (!is_array($definition['audience'] ?? null) || !is_array($definition['primary_metrics'] ?? null) || !is_array($definition['guardrails'] ?? null)) {
            $errors[] = 'invalid_experiment_arrays';
        }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $metrics = is_array($definition['primary_metrics'] ?? null) ? $definition['primary_metrics'] : [];
        if ($metrics === [] || count($metrics) > 10) {
            $errors[] = 'invalid_primary_metrics';
        }
        foreach (array_merge($metrics, is_array($definition['guardrails'] ?? null) ? $definition['guardrails'] : []) as $metric) {
            if (!is_array($metric)
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($metric['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($metric['metric_version'] ?? '')) !== 1
                || !in_array((string) ($metric['outcome_type'] ?? 'ratio'), ['ratio','number'], true)
                || !is_array($metric['base_dimensions'] ?? [])) {
                $errors[] = 'invalid_metric_contract';
                break;
            }
        }
        $design = $definition['design'] ?? null;
        if (!is_array($design)
            || (int) ($design['minimum_sample'] ?? 0) < 20
            || (int) ($design['duration_days'] ?? 0) < 1
            || (int) ($design['duration_days'] ?? 0) > 365
            || !in_array((string) ($design['multiple_testing_policy'] ?? ''), ['bonferroni','holm','fdr','single_primary'], true)
            || !in_array((string) ($design['missing_data_policy'] ?? ''), ['exclude','intention_to_treat','worst_case','report_only'], true)) {
            $errors[] = 'invalid_design';
        }
        $variants = is_array($design) ? ($design['variants'] ?? null) : null;
        if (!is_array($variants) || count($variants) < 2 || count($variants) > 10) {
            $errors[] = 'invalid_variants';
        } else {
            $seen = [];
            foreach ($variants as $variant) {
                $key = is_array($variant) ? (string) ($variant['key'] ?? '') : (string) $variant;
                if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $key) !== 1) {
                    $errors[] = 'invalid_variant_key';
                    break;
                }
                if (isset($seen[$key])) {
                    $errors[] = 'duplicate_variant';
                    break;
                }
                $seen[$key] = true;
            }
        }
        if (!empty($definition['starts_at']) && strtotime((string) $definition['starts_at']) === false) {
            $errors[] = 'invalid_starts_at';
        }
        if (!empty($definition['ends_at']) && strtotime((string) $definition['ends_at']) === false) {
            $errors[] = 'invalid_ends_at';
        }
        if (!empty($definition['starts_at']) && !empty($definition['ends_at']) && strtotime((string) $definition['ends_at']) <= strtotime((string) $definition['starts_at'])) {
            $errors[] = 'invalid_experiment_window';
        }
        return array_values(array_unique($errors));
    }
}
