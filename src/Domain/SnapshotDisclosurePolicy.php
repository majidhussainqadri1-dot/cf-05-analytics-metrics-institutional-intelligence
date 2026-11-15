<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class SnapshotDisclosurePolicy
{
    /**
     * Re-evaluate time-sensitive disclosure quality at read time.
     * DATETIME evidence is canonical UTC. A future timestamp is not proof
     * of current data, and malformed/relative values cannot be treated as
     * a fresh observation.
     *
     * @param array<int,mixed> $caveats
     * @param array<string,mixed> $definition
     * @return array{quality_status:string,data_through:?string,freshness_seconds:?int,caveats:array<int,string>}
     */
    public static function evaluate(string $storedQuality, mixed $dataThrough, array $caveats, array $definition): array
    {
        $quality = in_array($storedQuality, ['green','warning','degraded','unknown','suppressed','invalidated'], true)
            ? $storedQuality : 'unknown';
        $timestamp = self::canonicalDataThrough($dataThrough);
        $now = time();
        $future = $timestamp !== null && $timestamp > $now + 300;
        $freshnessSeconds = $timestamp !== null && !$future ? max(0, $now - $timestamp) : null;
        $declaredFreshness = max(60, (int) ($definition['freshness_seconds'] ?? DAY_IN_SECONDS));

        if (!in_array($quality, ['suppressed','invalidated'], true)) {
            if ($future) {
                $quality = 'unknown';
                $caveats[] = 'Data-through time is later than the permitted clock skew.';
            } elseif ($freshnessSeconds === null) {
                $quality = 'unknown';
                $caveats[] = 'Data-through time is unavailable or noncanonical.';
            } elseif ($freshnessSeconds > $declaredFreshness) {
                if ($quality === 'green') {
                    $quality = 'warning';
                }
                $caveats[] = 'Data is older than the declared freshness threshold.';
            }
        }

        return [
            'quality_status' => $quality,
            'data_through' => $timestamp !== null ? gmdate('c', $timestamp) : null,
            'freshness_seconds' => $freshnessSeconds,
            'caveats' => array_values(array_unique(array_map('strval', $caveats))),
        ];
    }

    private static function canonicalDataThrough(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $value) !== 1) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d H:i:s') === $value
            ? $time->getTimestamp() : null;
    }
}
