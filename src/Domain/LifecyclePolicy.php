<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class LifecyclePolicy
{
    /** @return array<string,array<int,string>> */
    public static function transitions(string $objectType): array
    {
        return match ($objectType) {
            'event_schema' => [
                'proposed' => ['privacy_review'],
                'privacy_review' => ['contract_tested'],
                'contract_tested' => ['active'],
                'active' => ['deprecated'],
                'deprecated' => ['retired'],
            ],
            'metric' => [
                'draft' => ['validated'],
                'validated' => ['approved'],
                'approved' => ['active'],
                'active' => ['deprecated','revised','invalidated'],
                'deprecated' => ['retired'],
                'revised' => ['retired'],
                'invalidated' => ['retired'],
            ],
            'dataset' => [
                'draft' => ['built'],
                'built' => ['quality_validated'],
                'quality_validated' => ['privacy_approved'],
                'privacy_approved' => ['published'],
                'published' => ['deprecated','invalidated'],
                'deprecated' => ['purged'],
                'invalidated' => ['purged'],
            ],
            default => [],
        };
    }

    public static function allowed(string $objectType, string $from, string $to): bool
    {
        return in_array($to, self::transitions($objectType)[$from] ?? [], true);
    }

    public static function requiresIndependentActor(string $objectType, string $targetState): bool
    {
        return ($objectType === 'event_schema' && $targetState === 'privacy_review')
            || ($objectType === 'metric' && $targetState === 'approved')
            || ($objectType === 'dataset' && in_array($targetState, ['privacy_approved','published'], true));
    }
}
