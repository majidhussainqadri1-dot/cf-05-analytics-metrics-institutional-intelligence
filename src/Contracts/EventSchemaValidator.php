<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class EventSchemaValidator
{
    /** @param array<string,mixed> $schema @return array<int,string> */
    public function errors(array $schema): array
    {
        $errors = [];
        $required = ['event_name','event_version','owner_module','purpose','privacy_class','retention_days','deletion_key_field','fields'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $schema)) {
                $errors[] = "missing_{$key}";
            }
        }

        if (isset($schema['event_name']) && !preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $schema['event_name'])) {
            $errors[] = 'invalid_event_name';
        }
        if (isset($schema['event_version']) && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $schema['event_version'])) {
            $errors[] = 'invalid_event_version';
        }
        if (isset($schema['privacy_class']) && !in_array((string) $schema['privacy_class'], ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $retention = (int) ($schema['retention_days'] ?? 0);
        if ($retention < 1 || $retention > 365) {
            $errors[] = 'invalid_retention_days';
        }
        if (isset($schema['fields']) && !is_array($schema['fields'])) {
            $errors[] = 'invalid_fields';
        }

        $forbidden = ['password','otp','cvv','pan','card_number','secret','private_key','clinical_note','prescription','message_body','identity_document'];
        foreach ((array) ($schema['fields'] ?? []) as $name => $definition) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name)) {
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
        return $schema;
    }
}
