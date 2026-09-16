<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class EventSchemaValidator
{
    private const TYPES = ['boolean','integer','number','enum','safe_string','timestamp','pseudonymous_ref'];

    /** @param array<string,mixed> $schema @return array<int,string> */
    public function errors(array $schema): array
    {
        $errors = [];
        $required = [
            'event_name','event_version','owner_module','fact_semantics','purpose',
            'privacy_class','retention_days','deletion_key_field','consumers','fields',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $schema)) {
                $errors[] = 'missing_' . $key;
            }
        }

        $allowedTopLevel = array_merge($required, [
            'requires_consent','minor_policy','late_window_seconds','allowed_regions',
            'allowed_providers','description','schema_owner_contact','correction_policy',
        ]);
        foreach (array_keys($schema) as $key) {
            if (!is_string($key) || !in_array($key, $allowedTopLevel, true)) {
                $errors[] = 'unknown_schema_key';
            }
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($schema['event_name'] ?? '')) !== 1) {
            $errors[] = 'invalid_event_name';
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($schema['event_version'] ?? '')) !== 1) {
            $errors[] = 'invalid_event_version';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($schema['owner_module'] ?? '')) !== 1) {
            $errors[] = 'invalid_owner_module';
        }
        $semantics = trim((string) ($schema['fact_semantics'] ?? ''));
        if (strlen($semantics) < 12 || strlen($semantics) > 2000) {
            $errors[] = 'invalid_fact_semantics';
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($schema['purpose'] ?? '')) !== 1) {
            $errors[] = 'invalid_purpose';
        }
        if (!in_array((string) ($schema['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $retention = filter_var($schema['retention_days'] ?? null, FILTER_VALIDATE_INT);
        if ($retention === false || $retention < 1 || $retention > 365) {
            $errors[] = 'invalid_retention_days';
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', (string) ($schema['deletion_key_field'] ?? '')) !== 1) {
            $errors[] = 'invalid_deletion_key_field';
        }
        if (isset($schema['requires_consent']) && !is_bool($schema['requires_consent'])) {
            $errors[] = 'invalid_requires_consent';
        }
        if (isset($schema['minor_policy']) && !in_array((string) $schema['minor_policy'], ['deny','aggregate_only','allow_with_verified_consent'], true)) {
            $errors[] = 'invalid_minor_policy';
        }
        if (isset($schema['late_window_seconds'])) {
            $lateWindow = filter_var($schema['late_window_seconds'], FILTER_VALIDATE_INT);
            if ($lateWindow === false || $lateWindow < 0 || $lateWindow > 90 * DAY_IN_SECONDS) {
                $errors[] = 'invalid_late_window';
            }
        }

        $consumers = $schema['consumers'] ?? null;
        if (!is_array($consumers) || $consumers === [] || count($consumers) > 50) {
            $errors[] = 'invalid_consumers';
        } else {
            $normalizedConsumers = [];
            foreach ($consumers as $consumer) {
                if (!is_string($consumer) || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $consumer) !== 1) {
                    $errors[] = 'invalid_consumer';
                    continue;
                }
                $normalizedConsumers[] = $consumer;
            }
            if (count(array_unique($normalizedConsumers)) !== count($normalizedConsumers)) {
                $errors[] = 'duplicate_consumer';
            }
        }
        $errors = array_merge($errors, $this->codeListErrors($schema['allowed_regions'] ?? null, 'region', '/^[A-Z0-9-]{2,32}$/'));
        $errors = array_merge($errors, $this->codeListErrors($schema['allowed_providers'] ?? null, 'provider', '/^[a-z0-9][a-z0-9_.-]{1,99}$/'));

        $fields = $schema['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || count($fields) > 100) {
            $errors[] = 'invalid_fields';
            return array_values(array_unique($errors));
        }

        $forbidden = [
            'password','passwd','pwd','otp','cvv','cvc','pan','card_number','secret','api_key','client_secret','private_key',
            'access_token','refresh_token','clinical_note','prescription','message_body',
            'identity_document','raw_query','full_name','email','phone','address','national_id',
        ];
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name) !== 1) {
                $errors[] = 'invalid_field_name';
                continue;
            }
            foreach ($forbidden as $fragment) {
                if (str_contains(strtolower($name), $fragment)) {
                    $errors[] = 'forbidden_field_' . $name;
                }
            }
            if (!is_array($definition)) {
                $errors[] = 'invalid_field_definition_' . $name;
                continue;
            }
            foreach (array_keys($definition) as $key) {
                if (!is_string($key) || !in_array($key, ['type','required','min','max','max_length','values','description'], true)) {
                    $errors[] = 'unknown_field_option_' . $name;
                }
            }
            $type = (string) ($definition['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                $errors[] = 'unsupported_field_type_' . $name;
                continue;
            }
            if (isset($definition['required']) && !is_bool($definition['required'])) {
                $errors[] = 'invalid_required_flag_' . $name;
            }
            if (in_array($type, ['integer','number'], true)) {
                foreach (['min','max'] as $bound) {
                    if (isset($definition[$bound]) && !is_int($definition[$bound]) && !is_float($definition[$bound])) {
                        $errors[] = 'invalid_' . $bound . '_' . $name;
                    }
                }
                if (isset($definition['min'], $definition['max']) && (float) $definition['min'] > (float) $definition['max']) {
                    $errors[] = 'invalid_bounds_' . $name;
                }
            }
            if ($type === 'safe_string') {
                $maximum = filter_var($definition['max_length'] ?? 100, FILTER_VALIDATE_INT);
                if ($maximum === false || $maximum < 1 || $maximum > 500) {
                    $errors[] = 'invalid_max_length_' . $name;
                }
            }
            if ($type === 'enum') {
                $values = $definition['values'] ?? null;
                if (!is_array($values) || $values === [] || count($values) > 100) {
                    $errors[] = 'invalid_enum_values_' . $name;
                } else {
                    $normalized = [];
                    foreach ($values as $value) {
                        if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                            $errors[] = 'invalid_enum_value_' . $name;
                            continue;
                        }
                        $encoded = get_debug_type($value) . ':' . json_encode($value);
                        $normalized[] = $encoded;
                    }
                    if (count(array_unique($normalized)) !== count($normalized)) {
                        $errors[] = 'duplicate_enum_value_' . $name;
                    }
                }
            }
        }

        $deletionField = (string) ($schema['deletion_key_field'] ?? '');
        if ($deletionField !== '' && !array_key_exists($deletionField, $fields)) {
            $errors[] = 'deletion_key_field_not_declared';
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $schema */
    public function normalize(array $schema): array
    {
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            foreach ($schema['fields'] as &$field) {
                if (is_array($field) && isset($field['values']) && is_array($field['values'])) {
                    usort($field['values'], static fn (mixed $a, mixed $b): int => strcmp(json_encode($a), json_encode($b)));
                }
                if (is_array($field)) {
                    ksort($field);
                }
            }
            unset($field);
            ksort($schema['fields']);
        }
        foreach (['consumers','allowed_regions','allowed_providers'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = array_values(array_unique(array_map('strval', $schema[$key])));
                sort($schema[$key]);
            }
        }
        $schema['requires_consent'] = (bool) ($schema['requires_consent'] ?? false);
        $schema['minor_policy'] = (string) ($schema['minor_policy'] ?? 'aggregate_only');
        $schema['late_window_seconds'] = (int) ($schema['late_window_seconds'] ?? 0);
        ksort($schema);
        return $schema;
    }

    /** @return array<int,string> */
    private function codeListErrors(mixed $value, string $label, string $pattern): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || count($value) > 100) {
            return ['invalid_allowed_' . $label . 's'];
        }
        $codes = [];
        foreach ($value as $code) {
            if (!is_string($code) || preg_match($pattern, $code) !== 1) {
                return ['invalid_allowed_' . $label . 's'];
            }
            $codes[] = $code;
        }
        return count(array_unique($codes)) === count($codes) ? [] : ['duplicate_allowed_' . $label];
    }
}
