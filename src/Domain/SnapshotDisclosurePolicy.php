<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class SnapshotDisclosurePolicy
{
    /**
     * Re-evaluate time-sensitive disclosure quality at read time.
     *
     * @param array<int,mixed> $caveats
     * @param array<string,mixed> $definition
     * @return array{quality_status:string,data_through:?string,freshness_seconds:?int,caveats:array<int,string>}
     */
    public static function evaluate(string $storedQuality, mixed $dataThrough, array $caveats, array $definition): array
    {
        $quality = in_array($storedQuality, ['green','warning','degraded','unknown','suppressed','invalidated'], true)
            ? $storedQuality
            : 'unknown';
        $timestamp = is_string($dataThrough) && $dataThrough !== '' ? strtotime($dataThrough) : false;
        $freshnessSeconds = $timestamp !== false ? max(0, time() - $timestamp) : null;
        $declaredFreshness = max(60, (int) ($definition['freshness_seconds'] ?? DAY_IN_SECONDS));

        if (!in_array($quality, ['suppressed','invalidated'], true)) {
            if ($freshnessSeconds === null) {
                $quality = 'unknown';
                $caveats[] = 'Data-through time is unavailable.';
            } elseif ($freshnessSeconds > $declaredFreshness) {
                if ($quality === 'green') {
                    $quality = 'warning';
                }
                $caveats[] = 'Data is older than the declared freshness threshold.';
            }
        }

        return [
            'quality_status' => $quality,
            'data_through' => $timestamp !== false ? gmdate('c', $timestamp) : null,
            'freshness_seconds' => $freshnessSeconds,
            'caveats' => array_values(array_unique(array_map('strval', $caveats))),
        ];
    }
}
