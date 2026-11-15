<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

/**
 * Source snapshot integrity for every disclosure route (API and dashboards).
 * Warehouse corruption is Unknown, not a numeric zero or valid-looking float.
 */
final class SnapshotEvidence
{
    public static function validDecimal(mixed $value): bool
    {
        return $value === null || ((is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', (string) $value) === 1
            && is_finite((float) $value));
    }

    /** @param array<string,mixed> $row */
    public static function validPublishedRow(array $row): bool
    {
        foreach (['value_decimal','numerator_decimal','denominator_decimal','coverage_decimal'] as $field) {
            if (!array_key_exists($field, $row) || !self::validDecimal($row[$field])) {
                return false;
            }
        }
        $rawCount = $row['cohort_size'] ?? null;
        if ((!is_int($rawCount) && !is_string($rawCount))
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $rawCount) !== 1
            || filter_var((string) $rawCount, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false) {
            return false;
        }
        $quality = $row['quality_status'] ?? null;
        if (!is_string($quality)
            || !in_array($quality, ['green','warning','degraded','unknown','suppressed','invalidated'], true)) {
            return false;
        }
        $coverage = $row['coverage_decimal'];
        return $coverage === null || ((float) $coverage >= 0.0 && (float) $coverage <= 1.0);
    }
}
