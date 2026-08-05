<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class FilterEvaluator
{
    /** @param array<string,mixed> $row @param array<int,array<string,mixed>> $filters */
    public static function matches(array $row, array $filters): bool
    {
        if (count($filters) > 50) {
            return false;
        }
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                return false;
            }
            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'eq');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
                return false;
            }
            $actual = $row[$field] ?? null;
            $expected = $filter['value'] ?? null;
            $ok = match ($operator) {
                'eq' => $actual === $expected,
                'neq' => $actual !== $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'not_in' => is_array($expected) && !in_array($actual, $expected, true),
                'exists' => array_key_exists($field, $row),
                'not_exists' => !array_key_exists($field, $row),
                'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
                'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
                'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}
