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
            $operator = (string) ($filter['operator'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
                return false;
            }
            $exists = array_key_exists($field, $row);
            if ($operator === 'exists') {
                if (!$exists) {
                    return false;
                }
                continue;
            }
            if ($operator === 'not_exists') {
                if ($exists) {
                    return false;
                }
                continue;
            }
            if (!$exists) {
                return false;
            }
            $actual = $row[$field];
            $expected = $filter['value'] ?? null;
            $ok = match ($operator) {
                'eq' => $actual === $expected,
                'neq' => $actual !== $expected,
                'in' => self::safeSet($expected) && in_array($actual, $expected, true),
                'not_in' => self::safeSet($expected) && !in_array($actual, $expected, true),
                'gte' => self::number($actual) !== null && self::number($expected) !== null && self::number($actual) >= self::number($expected),
                'gt' => self::number($actual) !== null && self::number($expected) !== null && self::number($actual) > self::number($expected),
                'lte' => self::number($actual) !== null && self::number($expected) !== null && self::number($actual) <= self::number($expected),
                'lt' => self::number($actual) !== null && self::number($expected) !== null && self::number($actual) < self::number($expected),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private static function safeSet(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item !== null) {
                return false;
            }
            if (is_float($item) && !is_finite($item)) {
                return false;
            }
            if (is_string($item) && strlen($item) > 190) {
                return false;
            }
        }
        return true;
    }
}
