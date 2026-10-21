<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

/**
 * Privacy-bounded, subject-deduplicated funnel statistics over approved dataset rows.
 * No identifiers or subject paths are returned or persisted by this calculator.
 */
final class FunnelAttributionCalculator
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $spec
     * @return array{0:?float,1:float,2:float}
     */
    public static function calculate(array $rows, array $spec, ?string $channelSelection = null): array
    {
        $subjectField = (string) $spec['subject_field'];
        $stepField = (string) $spec['step_field'];
        $timeField = (string) $spec['time_field'];
        $eligibleField = (string) $spec['eligible_field'];
        $channelField = (string) $spec['channel_field'];
        $entry = (string) $spec['entry_step'];
        $conversion = (string) $spec['conversion_step'];
        $maxSeconds = (int) $spec['window_seconds'];
        $policy = (string) $spec['attribution_policy'];
        $subjects = [];

        foreach ($rows as $row) {
            if (($row[$eligibleField] ?? null) !== true) {
                continue;
            }
            $subject = $row[$subjectField] ?? null;
            $step = $row[$stepField] ?? null;
            $time = $row[$timeField] ?? null;
            if (!is_string($subject) || preg_match('/^[a-f0-9]{64}$/', $subject) !== 1
                || !is_string($step) || !in_array($step, [$entry, $conversion], true)
                || !is_string($time)) {
                continue;
            }
            $timestamp = strtotime($time);
            if ($timestamp === false) {
                continue;
            }
            $channel = $row[$channelField] ?? 'unknown';
            $channel = is_string($channel) && preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $channel) === 1
                ? $channel : 'unknown';
            $subjects[$subject][] = [$timestamp, $step, $channel];
        }

        $denominator = 0.0;
        $numerator = 0.0;
        foreach ($subjects as $events) {
            usort($events, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $entryTime = null;
            $conversionTime = null;
            $path = [];
            foreach ($events as [$eventTime, $eventStep, $eventChannel]) {
                if ($entryTime === null) {
                    if ($eventStep !== $entry) {
                        continue;
                    }
                    $entryTime = $eventTime;
                }
                if ($eventTime - $entryTime > $maxSeconds) {
                    break;
                }
                $path[] = $eventChannel;
                if ($eventStep === $conversion && $eventTime > $entryTime) {
                    $conversionTime = $eventTime;
                    break;
                }
            }
            if ($entryTime === null) {
                continue;
            }
            $touches = array_values(array_unique($path));
            if ($channelSelection !== null && !in_array($channelSelection, $touches, true)) {
                continue;
            }
            $denominator += 1.0;
            if ($conversionTime === null) {
                continue;
            }
            if ($channelSelection === null) {
                $numerator += 1.0;
                continue;
            }
            $weight = match ($policy) {
                'first_touch' => ($touches[0] ?? null) === $channelSelection ? 1.0 : 0.0,
                'last_touch' => (end($path) ?: null) === $channelSelection ? 1.0 : 0.0,
                'linear' => $touches === [] ? 0.0 : (1.0 / count($touches)),
                default => 0.0,
            };
            $numerator += $weight;
        }
        return [$denominator > 0 ? $numerator / $denominator : null, $numerator, $denominator];
    }
}
