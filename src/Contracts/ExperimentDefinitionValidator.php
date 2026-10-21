<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class ExperimentDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        $allowed = ['name','hypothesis','assignment_owner','audience','primary_metrics','guardrails','design','privacy_class','involves_minors','medical_context','starts_at','ends_at'];
        if (array_diff(array_keys($definition), $allowed) !== []) { $errors[] = 'unsupported_experiment_field'; }
        foreach (['name','hypothesis','assignment_owner','audience','primary_metrics','guardrails','design','privacy_class'] as $key) {
            if (!array_key_exists($key, $definition)) { $errors[] = 'missing_' . $key; }
        }
        if (strlen(trim((string) ($definition['name'] ?? ''))) < 3 || strlen((string) ($definition['name'] ?? '')) > 190) { $errors[] = 'invalid_name'; }
        if (strlen(trim((string) ($definition['hypothesis'] ?? ''))) < 20 || strlen((string) ($definition['hypothesis'] ?? '')) > 5000) { $errors[] = 'invalid_hypothesis'; }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['assignment_owner'] ?? '')) !== 1) { $errors[] = 'invalid_assignment_owner'; }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) { $errors[] = 'invalid_privacy_class'; }
        if (isset($definition['involves_minors']) && !is_bool($definition['involves_minors'])) { $errors[] = 'invalid_involves_minors'; }
        if (isset($definition['medical_context']) && !is_bool($definition['medical_context'])) { $errors[] = 'invalid_medical_context'; }

        $audience = $definition['audience'] ?? null;
        if (!is_array($audience) || array_diff(array_keys($audience), ['description','eligibility_contract','exclusions']) !== []
            || !is_string($audience['description'] ?? null)
            || strlen(trim((string) ($audience['description'] ?? ''))) < 8
            || strlen((string) ($audience['description'] ?? '')) > 500
            || !is_string($audience['eligibility_contract'] ?? null)
            || preg_match('/^[a-z][a-z0-9_.-]{2,189}@[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($audience['eligibility_contract'] ?? '')) !== 1
            || !is_array($audience['exclusions'] ?? [])
            || count((array) ($audience['exclusions'] ?? [])) > 50) {
            $errors[] = 'invalid_audience';
        } elseif (array_is_list($audience['exclusions'])) {
            foreach ($audience['exclusions'] as $exclusion) {
                if (!is_string($exclusion) || preg_match('/^[a-z0-9][a-z0-9_.:-]{1,99}$/', $exclusion) !== 1) {
                    $errors[] = 'invalid_audience_exclusion';
                }
            }
        } else {
            $errors[] = 'invalid_audience_exclusions';
        }

        $primary = is_array($definition['primary_metrics'] ?? null) ? array_values($definition['primary_metrics']) : [];
        $guardrails = is_array($definition['guardrails'] ?? null) ? array_values($definition['guardrails']) : [];
        if ($primary === [] || count($primary) > 10 || count($guardrails) > 20) { $errors[] = 'invalid_metric_counts'; }
        $seenMetrics = [];
        foreach (array_merge($primary, $guardrails) as $metric) {
            $allowedMetric = ['metric_id','metric_version','outcome_type','base_dimensions','minimum_effect','threshold','harm_direction'];
            if (!is_array($metric) || array_diff(array_keys($metric), $allowedMetric) !== []
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($metric['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($metric['metric_version'] ?? '')) !== 1
                || !in_array((string) ($metric['outcome_type'] ?? 'ratio'), ['ratio','number'], true)
                || !is_array($metric['base_dimensions'] ?? [])
                || (($metric['base_dimensions'] ?? []) !== [] && array_is_list($metric['base_dimensions'] ?? []))) {
                $errors[] = 'invalid_metric_contract'; continue;
            }
            if (isset($metric['minimum_effect']) && (!is_int($metric['minimum_effect']) && !is_float($metric['minimum_effect']) || !is_finite((float) $metric['minimum_effect']) || (float) $metric['minimum_effect'] < 0)) { $errors[] = 'invalid_minimum_effect'; }
            if (isset($metric['threshold']) && (!is_int($metric['threshold']) && !is_float($metric['threshold']) || !is_finite((float) $metric['threshold']) || (float) $metric['threshold'] < 0)) { $errors[] = 'invalid_guardrail_threshold'; }
            if (isset($metric['harm_direction']) && !in_array((string) $metric['harm_direction'], ['increase','decrease'], true)) { $errors[] = 'invalid_harm_direction'; }
            foreach ((array) ($metric['base_dimensions'] ?? []) as $dimension => $value) {
                if (!is_string($dimension) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dimension) !== 1
                    || (!is_scalar($value) && $value !== null)
                    || (is_float($value) && !is_finite($value))) {
                    $errors[] = 'invalid_base_dimension';
                }
            }
            $key = (string) $metric['metric_id'] . '@' . (string) $metric['metric_version'] . '|' . json_encode($metric['base_dimensions']);
            if (isset($seenMetrics[$key])) { $errors[] = 'duplicate_metric_contract'; }
            $seenMetrics[$key] = true;
        }

        $design = $definition['design'] ?? null;
        $allowedDesign = ['minimum_sample','duration_days','multiple_testing_policy','missing_data_policy','minimum_practical_effect','variants','randomization_unit'];
        $minimumSample = is_array($design) ? ($design['minimum_sample'] ?? null) : null;
        $durationDays = is_array($design) ? ($design['duration_days'] ?? null) : null;
        $minimumPracticalEffect = is_array($design) ? ($design['minimum_practical_effect'] ?? null) : null;
        if (!is_array($design) || array_diff(array_keys($design), $allowedDesign) !== []
            || !is_int($minimumSample) || $minimumSample < 20 || $minimumSample > 10000000
            || !is_int($durationDays) || $durationDays < 1 || $durationDays > 365
            || (!is_int($minimumPracticalEffect) && !is_float($minimumPracticalEffect))
            || !is_finite((float) $minimumPracticalEffect) || (float) $minimumPracticalEffect < 0
            || !in_array((string) ($design['multiple_testing_policy'] ?? ''), ['bonferroni','holm','fdr','single_primary'], true)
            || !in_array((string) ($design['missing_data_policy'] ?? ''), ['exclude','intention_to_treat','worst_case','report_only'], true)
            || !in_array((string) ($design['randomization_unit'] ?? ''), ['pseudonymous_subject','session','organization'], true)) {
            $errors[] = 'invalid_design';
        }
        $variants = is_array($design) ? ($design['variants'] ?? null) : null;
        if (!is_array($variants) || count($variants) < 2 || count($variants) > 10) {
            $errors[] = 'invalid_variants';
        } else {
            $seen = []; $allocationTotal = 0.0;
            foreach ($variants as $variant) {
                if (!is_array($variant) || array_diff(array_keys($variant), ['key','allocation']) !== []) { $errors[] = 'invalid_variant'; continue; }
                $key = (string) ($variant['key'] ?? '');
                $allocation = $variant['allocation'] ?? null;
                if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $key) !== 1 || !is_int($allocation) && !is_float($allocation) || !is_finite((float) $allocation) || (float) $allocation <= 0 || (float) $allocation > 1) { $errors[] = 'invalid_variant'; }
                if (isset($seen[$key])) { $errors[] = 'duplicate_variant'; }
                $seen[$key] = true; $allocationTotal += (float) $allocation;
            }
            if (abs($allocationTotal - 1.0) > 0.000001) { $errors[] = 'invalid_variant_allocation_total'; }
        }
        $start = !empty($definition['starts_at']) ? $this->strictTimestamp((string) $definition['starts_at']) : null;
        $end = !empty($definition['ends_at']) ? $this->strictTimestamp((string) $definition['ends_at']) : null;
        if (!empty($definition['starts_at']) && $start === null) { $errors[] = 'invalid_starts_at'; }
        if (!empty($definition['ends_at']) && $end === null) { $errors[] = 'invalid_ends_at'; }
        if ($start !== null && $end !== null && ($end <= $start || $end - $start > 366 * DAY_IN_SECONDS)) { $errors[] = 'invalid_experiment_window'; }
        return array_values(array_unique($errors));
    }
    private function strictTimestamp(string $value): ?int { if (strlen($value)>35 || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/',$value,$m)!==1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null; $t=strtotime($value); return $t===false?null:$t; }
}
