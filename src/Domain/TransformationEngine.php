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
            'timestamp' => is_string($value) ? $this->canonicalTimestamp($value) : null,
            'pseudonymous_ref' => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null,
            'string' => is_string($value) && strlen($value) <= max(1, min(500, (int) ($definition['max_length'] ?? 190))) ? $value : null,
            default => null,
        };
    }

    /** Reject relative times; accept only RFC3339 or canonical UTC SQL datetimes. */
    private function canonicalTimestamp(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value, $match) === 1
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $time = strtotime($value);
            return $time === false ? null : gmdate('c', $time);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $value, $match) === 1
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $utc = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            return $utc !== false && $utc->format('Y-m-d H:i:s') === $value ? $utc->format('c') : null;
        }
        return null;
    }

}
