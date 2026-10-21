<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class DatasetDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        $required = ['dataset_id','dataset_version','name','owner_module','grain','privacy_class','retention_days','region_code','provider_id','sources','fields'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = 'missing_' . $key;
            }
        }
        $allowed = array_merge($required, ['description','quality_policy','historical_semantics','owner_contact']);
        foreach (array_keys($definition) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $errors[] = 'unknown_dataset_key';
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($definition['dataset_id'] ?? '')) !== 1) {
            $errors[] = 'invalid_dataset_id';
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($definition['dataset_version'] ?? '')) !== 1) {
            $errors[] = 'invalid_dataset_version';
        }
        $name = $definition['name'] ?? null;
        if (!is_string($name) || strlen(trim($name)) < 3 || strlen($name) > 190) {
            $errors[] = 'invalid_name';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['owner_module'] ?? '')) !== 1) {
            $errors[] = 'invalid_owner_module';
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', (string) ($definition['grain'] ?? '')) !== 1) {
            $errors[] = 'invalid_grain';
        }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $retention = filter_var($definition['retention_days'] ?? null, FILTER_VALIDATE_INT);
        if ($retention === false || $retention < 1 || $retention > 730) {
            $errors[] = 'invalid_retention_days';
        }
        if (preg_match('/^[A-Z0-9-]{2,32}$/', (string) ($definition['region_code'] ?? '')) !== 1) {
            $errors[] = 'invalid_region_code';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', (string) ($definition['provider_id'] ?? '')) !== 1) {
            $errors[] = 'invalid_provider_id';
        }
        if (isset($definition['historical_semantics']) && !in_array((string) $definition['historical_semantics'], ['current','as_occurred'], true)) {
            $errors[] = 'invalid_historical_semantics';
        }

        $sources = $definition['sources'] ?? null;
        if (!is_array($sources) || $sources === [] || count($sources) > 20) {
            $errors[] = 'invalid_sources';
        } else {
            $seen = [];
            foreach ($sources as $index => $source) {
                if (!is_array($source)
                    || array_diff(array_keys($source), ['event_name','event_version']) !== []
                    || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($source['event_name'] ?? '')) !== 1
                    || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($source['event_version'] ?? '')) !== 1) {
                    $errors[] = 'invalid_source_' . $index;
                    continue;
                }
                $key = $source['event_name'] . '@' . $source['event_version'];
                if (isset($seen[$key])) {
                    $errors[] = 'duplicate_source';
                }
                $seen[$key] = true;
            }
        }

        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || count($fields) > 100) {
            $errors[] = 'invalid_fields';
        } else {
            $forbidden = ['password','otp','secret','api_key','client_secret','private_key','token','clinical_note','prescription','message_body','identity_document','email','phone','address','national_id','raw_query'];
            foreach ($fields as $fieldName => $field) {
                if (!is_string($fieldName) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fieldName) !== 1 || !is_array($field)) {
                    $errors[] = 'invalid_field_definition';
                    continue;
                }
                foreach ($forbidden as $fragment) {
                    if (str_contains(strtolower($fieldName), $fragment)) {
                        $errors[] = 'forbidden_field_' . $fieldName;
                    }
                }
                if (array_diff(array_keys($field), ['type','from','required','max_length','description']) !== []) {
                    $errors[] = 'unknown_field_option_' . $fieldName;
                }
                $type = (string) ($field['type'] ?? '');
                if (!in_array($type, ['boolean','integer','number','string','timestamp','pseudonymous_ref'], true)) {
                    $errors[] = 'invalid_field_type_' . $fieldName;
                }
                $from = (string) ($field['from'] ?? '');
                if (preg_match('/^(event|properties)\.[a-z][a-z0-9_]{0,99}$/', $from) !== 1) {
                    $errors[] = 'invalid_field_mapping_' . $fieldName;
                }
                if (isset($field['required']) && !is_bool($field['required'])) {
                    $errors[] = 'invalid_required_flag_' . $fieldName;
                }
                if ($type === 'string') {
                    $maximum = filter_var($field['max_length'] ?? 190, FILTER_VALIDATE_INT);
                    if ($maximum === false || $maximum < 1 || $maximum > 500) {
                        $errors[] = 'invalid_max_length_' . $fieldName;
                    }
                }
                if ($type === 'pseudonymous_ref' && !str_starts_with($from, 'event.') && !str_starts_with($from, 'properties.')) {
                    $errors[] = 'invalid_pseudonymous_mapping_' . $fieldName;
                }
            }
        }
        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function normalize(array $definition): array
    {
        $sources = [];
        foreach ((array) ($definition['sources'] ?? []) as $source) {
            if (is_array($source)) {
                ksort($source);
                $sources[(string) ($source['event_name'] ?? '') . '@' . (string) ($source['event_version'] ?? '')] = $source;
            }
        }
        ksort($sources);
        $definition['sources'] = array_values($sources);
        if (is_array($definition['fields'] ?? null)) {
            foreach ($definition['fields'] as &$field) {
                if (is_array($field)) {
                    $field['required'] = (bool) ($field['required'] ?? false);
                    if (($field['type'] ?? '') === 'string') {
                        $field['max_length'] = max(1, min(500, (int) ($field['max_length'] ?? 190)));
                    }
                    ksort($field);
                }
            }
            unset($field);
            ksort($definition['fields']);
        }
        $definition['historical_semantics'] = (string) ($definition['historical_semantics'] ?? 'current');
        ksort($definition);
        return $definition;
    }
}
