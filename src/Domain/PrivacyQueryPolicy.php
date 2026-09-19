<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class PrivacyQueryPolicy
{
    /** @param array<string,mixed> $definition @param array<string,mixed> $dimensions */
    public static function violations(array $definition, array $dimensions): array
    {
        $errors = [];
        $controls = is_array($definition['privacy_controls'] ?? null) ? $definition['privacy_controls'] : [];
        $maxDimensions = max(1, min(10, (int) ($controls['max_dimensions_per_query'] ?? 4)));
        if (count($dimensions) > $maxDimensions) {
            $errors[] = 'too_many_dimensions';
        }

        $policies = is_array($definition['dimension_policies'] ?? null) ? $definition['dimension_policies'] : [];
        foreach ($dimensions as $name => $value) {
            if (!is_string($name) || !isset($policies[$name]) || !is_array($policies[$name])) {
                $errors[] = 'dimension_policy_missing';
                continue;
            }
            if (is_array($value) || is_object($value) || is_resource($value)
                || (is_float($value) && !is_finite($value))
                || (is_string($value) && strlen($value) > 100)) {
                $errors[] = 'invalid_dimension_value';
            }
        }

        $requested = array_values(array_map('strval', array_keys($dimensions)));
        sort($requested);
        foreach ((array) ($definition['prohibited_dimension_combinations'] ?? []) as $combination) {
            if (!is_array($combination)) {
                continue;
            }
            $blocked = array_values(array_unique(array_map('strval', $combination)));
            sort($blocked);
            if ($blocked !== [] && count(array_intersect($blocked, $requested)) === count($blocked)) {
                $errors[] = 'prohibited_dimension_combination';
                break;
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $dimensions */
    public static function effectiveMinimum(array $definition, array $dimensions, int $globalMinimum): int
    {
        $minimum = max(5, $globalMinimum, (int) ($definition['minimum_cohort'] ?? 0));
        $policies = is_array($definition['dimension_policies'] ?? null) ? $definition['dimension_policies'] : [];
        foreach (array_keys($dimensions) as $name) {
            $policy = is_array($policies[$name] ?? null) ? $policies[$name] : [];
            $minimum = max($minimum, (int) ($policy['minimum_cohort'] ?? 0));
        }
        return min(1000000, $minimum);
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $dimensions */
    public static function privacyCost(array $definition, array $dimensions): int
    {
        $weights = ['public' => 1, 'standard' => 2, 'sensitive' => 4, 'high' => 8];
        $policies = is_array($definition['dimension_policies'] ?? null) ? $definition['dimension_policies'] : [];
        $cost = 1;
        foreach (array_keys($dimensions) as $name) {
            $policy = is_array($policies[$name] ?? null) ? $policies[$name] : [];
            $cost += $weights[(string) ($policy['sensitivity'] ?? 'standard')] ?? 4;
        }
        return $cost;
    }

    /**
     * @param array{window_start:string,window_end:string,dimension_names:array<int,string>,dimension_hashes:array<string,string>,cohort_size:int} $current
     * @param array{window_start?:string,window_end?:string,dimension_names?:array<int,string>,dimension_hashes?:array<string,string>,cohort_size?:int} $previous
     */
    public static function differencingRisk(array $current, array $previous, int $floor): bool
    {
        $currentStart = strtotime($current['window_start']);
        $currentEnd = strtotime($current['window_end']);
        $previousStart = strtotime((string) ($previous['window_start'] ?? ''));
        $previousEnd = strtotime((string) ($previous['window_end'] ?? ''));
        if ($currentStart === false || $currentEnd === false || $previousStart === false || $previousEnd === false
            || $currentStart >= $currentEnd || $previousStart >= $previousEnd) {
            return false;
        }
        $sameWindow = $previousStart === $currentStart && $previousEnd === $currentEnd;
        $nestedWindow = ($currentStart >= $previousStart && $currentEnd <= $previousEnd)
            || ($previousStart >= $currentStart && $previousEnd <= $currentEnd);

        $currentNames = array_values(array_unique(array_map('strval', $current['dimension_names'])));
        $previousNames = array_values(array_unique(array_map('strval', (array) ($previous['dimension_names'] ?? []))));
        sort($currentNames);
        sort($previousNames);
        $currentHashes = is_array($current['dimension_hashes']) ? $current['dimension_hashes'] : [];
        $previousHashes = is_array($previous['dimension_hashes'] ?? null) ? $previous['dimension_hashes'] : [];

        if ($sameWindow && $currentNames === $previousNames && $currentHashes === $previousHashes) {
            return false;
        }

        $difference = abs((int) $current['cohort_size'] - (int) ($previous['cohort_size'] ?? 0));
        if ($difference >= max(1, $floor)) {
            return false;
        }

        if (!$sameWindow) {
            return $nestedWindow && $currentNames === $previousNames && $currentHashes === $previousHashes;
        }

        $currentSubset = count(array_diff($currentNames, $previousNames)) === 0;
        $previousSubset = count(array_diff($previousNames, $currentNames)) === 0;
        if ($currentSubset || $previousSubset) {
            return true;
        }

        if ($currentNames === $previousNames) {
            $changed = 0;
            foreach ($currentNames as $name) {
                if (($currentHashes[$name] ?? '') !== ($previousHashes[$name] ?? '')) {
                    $changed++;
                }
            }
            return $changed <= 1;
        }

        return false;
    }
}
