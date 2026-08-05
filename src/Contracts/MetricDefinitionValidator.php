<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class MetricDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        foreach (['metric_id','metric_version','name','owner_module','business_question','numerator','denominator','grain','window','timezone','dimensions','privacy_class','minimum_cohort'] as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = "missing_{$key}";
            }
        }

        if (isset($definition['metric_id']) && !preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $definition['metric_id'])) {
            $errors[] = 'invalid_metric_id';
        }
        if (isset($definition['metric_version']) && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $definition['metric_version'])) {
            $errors[] = 'invalid_metric_version';
        }
        if (isset($definition['privacy_class']) && !in_array((string) $definition['privacy_class'], ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        if ((int) ($definition['minimum_cohort'] ?? 0) < 5) {
            $errors[] = 'minimum_cohort_too_small';
        }
        if (!is_array($definition['dimensions'] ?? null)) {
            $errors[] = 'invalid_dimensions';
        } else {
            foreach ($definition['dimensions'] as $dimension) {
                if (!is_string($dimension) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dimension)) {
                    $errors[] = 'invalid_dimension';
                }
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
        ksort($definition);
        return $definition;
    }
}
