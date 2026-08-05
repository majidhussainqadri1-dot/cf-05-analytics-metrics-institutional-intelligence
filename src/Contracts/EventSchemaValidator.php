<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class EventSchemaValidator
{
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
        if (isset($schema['event_name']) && preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $schema['event_name']) !== 1) {
            $errors[] = 'invalid_event_name';
        }
        if (isset($schema['event_version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $schema['event_version']) !== 1) {
            $errors[] = 'invalid_event_version';
        }
        if (isset($schema['owner_module']) && preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) $schema['owner_module']) !== 1) {
            $errors[] = 'invalid_owner_module';
        }
        if (strlen(trim((string) ($schema['fact_semantics'] ?? ''))) < 12) {
            $errors[] = 'invalid_fact_semantics';
        }
        if (!in_array((string) ($schema['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $retention = (int) ($schema['retention_days'] ?? 0);
        if ($retention < 1 || $retention > 365) {
            $errors[] = 'invalid_retention_days';
        }
        if (!is_array($schema['consumers'] ?? null) || $schema['consumers'] === [] || count($schema['consumers']) > 50) {
            $errors[] = 'invalid_consumers';
        }
        if (isset($schema['minor_policy']) && !in_array((string) $schema['minor_policy'], ['deny','aggregate_only','allow_with_verified_consent'], true)) {
            $errors[] = 'invalid_minor_policy';
        }
        if (isset($schema['late_window_seconds']) && ((int) $schema['late_window_seconds'] < 0 || (int) $schema['late_window_seconds'] > 90 * DAY_IN_SECONDS)) {
            $errors[] = 'invalid_late_window';
        }
        if (isset($schema['allowed_regions']) && !is_array($schema['allowed_regions'])) {
            $errors[] = 'invalid_allowed_regions';
        }

        $fields = $schema['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || count($fields) > 100) {
            $errors[] = 'invalid_fields';
            return array_values(array_unique($errors));
        }
        $forbidden = [
            'password','passwd','otp','cvv','cvc','pan','card_number','secret','private_key',
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
            if (!is_array($definition) || !isset($definition['type'])) {
                $errors[] = 'invalid_field_definition_' . $name;
                continue;
            }
            if (!in_array((string) $definition['type'], ['boolean','integer','number','enum','safe_string','timestamp','pseudonymous_ref'], true)) {
                $errors[] = 'unsupported_field_type_' . $name;
            }
            if (($definition['type'] ?? '') === 'enum' && (!is_array($definition['values'] ?? null) || count($definition['values']) > 100)) {
                $errors[] = 'invalid_enum_values_' . $name;
            }
        }
        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $schema */
    public function normalize(array $schema): array
    {
        ksort($schema);
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            ksort($schema['fields']);
        }
        if (isset($schema['consumers']) && is_array($schema['consumers'])) {
            $schema['consumers'] = array_values(array_unique(array_map('strval', $schema['consumers'])));
            sort($schema['consumers']);
        }
        return $schema;
    }
}
