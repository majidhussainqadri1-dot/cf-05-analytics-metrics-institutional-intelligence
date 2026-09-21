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
            $normalized = $this->normalize($value, (string) ($definition['type'] ?? 'string'), $definition);
            if ($value !== null && $normalized === null) {
                throw new \RuntimeException('Dataset field normalization failed: ' . $name);
            }
            if (($definition['required'] ?? false) === true && $normalized === null) {
                throw new \RuntimeException('Required dataset field is unavailable: ' . $name);
            }
            $row[$name] = $normalized;
        }
        return $row;
    }

    /** @param array<string,mixed> $definition */
    private function normalize(mixed $value, string $type, array $definition): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'boolean' => is_bool($value) ? $value : (is_int($value) && ($value === 0 || $value === 1) ? (bool) $value : null),
            'integer' => is_int($value) ? $value : null,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null,
            'timestamp' => is_string($value) && strtotime($value) !== false ? gmdate('c', (int) strtotime($value)) : null,
            'pseudonymous_ref' => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null,
            'string' => is_string($value) && strlen($value) <= max(1, min(500, (int) ($definition['max_length'] ?? 190))) ? $value : null,
            default => null,
        };
    }
}
