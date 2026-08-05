<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class DatasetDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors = [];
        foreach (['dataset_id','dataset_version','name','owner_module','grain','privacy_class','retention_days','region_code','provider_id','sources','fields'] as $key) {
            if (!array_key_exists($key, $definition)) {
                $errors[] = 'missing_' . $key;
            }
        }
        if (isset($definition['dataset_id']) && preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) $definition['dataset_id']) !== 1) {
            $errors[] = 'invalid_dataset_id';
        }
        if (isset($definition['dataset_version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $definition['dataset_version']) !== 1) {
            $errors[] = 'invalid_dataset_version';
        }
        if (!in_array((string) ($definition['privacy_class'] ?? ''), ['C1','C2','C3'], true)) {
            $errors[] = 'invalid_privacy_class';
        }
        $retention = (int) ($definition['retention_days'] ?? 0);
        if ($retention < 1 || $retention > 730) {
            $errors[] = 'invalid_retention_days';
        }
        $sources = $definition['sources'] ?? null;
        if (!is_array($sources) || $sources === [] || count($sources) > 20) {
            $errors[] = 'invalid_sources';
        } else {
            foreach ($sources as $index => $source) {
                if (!is_array($source)
                    || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($source['event_name'] ?? '')) !== 1
                    || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($source['event_version'] ?? '')) !== 1) {
                    $errors[] = 'invalid_source_' . $index;
                }
            }
        }
        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || count($fields) > 100) {
            $errors[] = 'invalid_fields';
        } else {
            foreach ($fields as $name => $field) {
                if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || !is_array($field)) {
                    $errors[] = 'invalid_field_definition';
                    continue;
                }
                if (!in_array((string) ($field['type'] ?? ''), ['boolean','integer','number','string','timestamp','pseudonymous_ref'], true)) {
                    $errors[] = 'invalid_field_type_' . $name;
                }
                $from = (string) ($field['from'] ?? '');
                if (preg_match('/^(event|properties)\.[a-z][a-z0-9_]{0,99}$/', $from) !== 1) {
                    $errors[] = 'invalid_field_mapping_' . $name;
                }
            }
        }
        return array_values(array_unique($errors));
    }
}
