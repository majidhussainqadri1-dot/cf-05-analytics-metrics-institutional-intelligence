<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Json;

final class TransformationEngine
{
    /** @param array<string,mixed> $event @param array<string,mixed> $fields @return array<string,mixed> */
    public function map(array $event, array $fields): array
    {
        $properties = is_array($event['properties'] ?? null)
            ? $event['properties']
            : Json::object((string) ($event['properties_json'] ?? '{}'));
        $row = [];
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            $from = explode('.', (string) ($definition['from'] ?? ''), 2);
            $value = null;
            if (count($from) === 2 && $from[0] === 'event') {
                $value = $event[$from[1]] ?? null;
            } elseif (count($from) === 2 && $from[0] === 'properties') {
                $value = $properties[$from[1]] ?? null;
            }
            $row[$name] = $this->normalize($value, (string) ($definition['type'] ?? 'string'));
        }
        return $row;
    }

    private function normalize(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'boolean' => is_bool($value) ? $value : null,
            'integer' => is_int($value) ? $value : (is_numeric($value) ? (int) $value : null),
            'number' => is_numeric($value) ? (float) $value : null,
            'timestamp' => is_string($value) && strtotime($value) !== false ? gmdate('c', (int) strtotime($value)) : null,
            'pseudonymous_ref' => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
