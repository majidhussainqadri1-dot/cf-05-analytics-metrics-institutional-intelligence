<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class LifecyclePolicy
{
    /** @return array<string,array<int,string>> */
    public static function eventSchemaTransitions(): array
    {
        return [
            'proposed' => ['privacy_review'],
            'privacy_review' => ['contract_tested'],
            'contract_tested' => ['active'],
            'active' => ['deprecated'],
            'deprecated' => ['retired'],
        ];
    }

    /** @return array<string,array<int,string>> */
    public static function metricTransitions(): array
    {
        return [
            'draft' => ['validated'],
            'validated' => ['approved'],
            'approved' => ['active'],
            'active' => ['deprecated', 'revised', 'invalidated'],
            'deprecated' => ['retired'],
            'revised' => ['retired'],
            'invalidated' => ['retired'],
        ];
    }

    public static function allowed(string $objectType, string $from, string $to): bool
    {
        $map = $objectType === 'event_schema' ? self::eventSchemaTransitions() : ($objectType === 'metric' ? self::metricTransitions() : []);
        return in_array($to, $map[$from] ?? [], true);
    }

    public static function requiresIndependentActor(string $objectType, string $targetState): bool
    {
        return ($objectType === 'event_schema' && $targetState === 'privacy_review')
            || ($objectType === 'metric' && $targetState === 'approved');
    }
}
