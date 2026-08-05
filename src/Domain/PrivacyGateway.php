<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;

final class PrivacyGateway
{
    private string $pseudonymKey;
    private SensitiveValueDetector $sensitiveDetector;

    public function __construct(string $pseudonymKey)
    {
        if (strlen($pseudonymKey) < 32) {
            throw new \InvalidArgumentException('Pseudonym key must be at least 32 bytes.');
        }
        $this->pseudonymKey = $pseudonymKey;
        $this->sensitiveDetector = new SensitiveValueDetector();
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $schema
     * @return array{accepted:bool,properties:array<string,mixed>,errors:array<int,string>,actor_ref:?string,object_ref:?string,deletion_key:?string}
     */
    public function process(array $payload, array $schema): array
    {
        $errors = [];
        $properties = [];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $incoming = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];

        foreach ($incoming as $name => $value) {
            if (!is_string($name) || !isset($fields[$name]) || !is_array($fields[$name])) {
                continue;
            }
            $definition = $fields[$name];
            $type = (string) ($definition['type'] ?? '');
            $normalized = $this->normalizeValue($type, $value, $definition, $name, $errors);
            if ($normalized !== null) {
                $properties[$name] = $normalized;
            }
        }

        $actor = $this->pseudonymizeNullable($payload['actor_ref'] ?? null, 'actor');
        $object = $this->pseudonymizeNullable($payload['object_ref'] ?? null, 'object');
        $deletion = $this->pseudonymizeNullable($payload['deletion_key'] ?? null, 'deletion');

        if (($payload['consent_granted'] ?? true) !== true && ($schema['requires_consent'] ?? false) === true) {
            $errors[] = 'consent_required';
        }
        if (($payload['is_minor'] ?? false) === true && ($schema['minor_policy'] ?? 'aggregate_only') === 'deny') {
            $errors[] = 'minor_event_denied';
        }

        return [
            'accepted' => $errors === [],
            'properties' => $properties,
            'errors' => array_values(array_unique($errors)),
            'actor_ref' => $actor,
            'object_ref' => $object,
            'deletion_key' => $deletion,
        ];
    }

    /** @param array<string,mixed> $definition @param array<int,string> $errors */
    private function normalizeValue(string $type, mixed $value, array $definition, string $name, array &$errors): mixed
    {
        return match ($type) {
            'boolean' => is_bool($value) ? $value : $this->reject($errors, 'invalid_boolean_' . $name),
            'integer' => is_int($value) ? $this->boundedInteger($value, $definition, $errors, $name) : $this->reject($errors, 'invalid_integer_' . $name),
            'number' => (is_int($value) || is_float($value)) ? $this->boundedNumber((float) $value, $definition, $errors, $name) : $this->reject($errors, 'invalid_number_' . $name),
            'enum' => is_scalar($value) && in_array((string) $value, array_map('strval', (array) ($definition['values'] ?? [])), true) ? (string) $value : $this->reject($errors, 'invalid_enum_' . $name),
            'safe_string' => is_string($value) ? $this->safeString($value, $definition, $errors, $name) : $this->reject($errors, 'invalid_string_' . $name),
            'timestamp' => is_string($value) && strtotime($value) !== false ? gmdate('c', (int) strtotime($value)) : $this->reject($errors, 'invalid_timestamp_' . $name),
            'pseudonymous_ref' => is_scalar($value) ? $this->pseudonymize((string) $value, 'field:' . $name) : $this->reject($errors, 'invalid_ref_' . $name),
            default => $this->reject($errors, 'unsupported_type_' . $name),
        };
    }

    /** @param array<string,mixed> $definition @param array<int,string> $errors */
    private function boundedInteger(int $value, array $definition, array &$errors, string $name): ?int
    {
        $min = isset($definition['min']) ? (int) $definition['min'] : PHP_INT_MIN;
        $max = isset($definition['max']) ? (int) $definition['max'] : PHP_INT_MAX;
        if ($value < $min || $value > $max) {
            $errors[] = 'out_of_range_' . $name;
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $definition @param array<int,string> $errors */
    private function boundedNumber(float $value, array $definition, array &$errors, string $name): ?float
    {
        if (!is_finite($value)) {
            $errors[] = 'invalid_number_' . $name;
            return null;
        }
        $min = isset($definition['min']) ? (float) $definition['min'] : -PHP_FLOAT_MAX;
        $max = isset($definition['max']) ? (float) $definition['max'] : PHP_FLOAT_MAX;
        if ($value < $min || $value > $max) {
            $errors[] = 'out_of_range_' . $name;
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $definition @param array<int,string> $errors */
    private function safeString(string $value, array $definition, array &$errors, string $name): ?string
    {
        $max = max(1, min(500, (int) ($definition['max_length'] ?? 100)));
        $value = trim(wp_strip_all_tags($value));
        if ($value === '' && ($definition['required'] ?? false) === true) {
            $errors[] = 'empty_' . $name;
            return null;
        }
        $value = Text::truncate($value, $max);
        $violations = $this->sensitiveDetector->violations($value);
        if ($violations !== []) {
            $errors[] = 'sensitive_value_' . $name;
            return null;
        }
        return $value;
    }

    /** @param array<int,string> $errors */
    private function reject(array &$errors, string $error): mixed
    {
        $errors[] = $error;
        return null;
    }

    private function pseudonymizeNullable(mixed $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        return $this->pseudonymize((string) $value, $context);
    }

    private function pseudonymize(string $value, string $context): string
    {
        return hash_hmac('sha256', $context . '|' . $value, $this->pseudonymKey);
    }
}
