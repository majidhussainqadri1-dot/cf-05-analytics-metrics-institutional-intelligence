<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class HealthService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function report(bool $includeCounts = false): array
    {
        $tables = [];
        $healthy = true;
        foreach ($this->db->tables() as $name => $table) {
            $exists = $this->db->exists($name);
            $healthy = $healthy && $exists;
            $tables[$name] = [
                'status' => $exists ? 'available' : 'missing',
                'count' => $includeCounts && $exists ? $this->db->count($name) : null,
            ];
        }

        $secretConfigured = defined('SMAI_INGESTION_SECRET') && is_string(SMAI_INGESTION_SECRET) && strlen(SMAI_INGESTION_SECRET) >= 32;
        $pseudonymConfigured = defined('SMAI_PSEUDONYM_KEY') && is_string(SMAI_PSEUDONYM_KEY) && strlen(SMAI_PSEUDONYM_KEY) >= 32;

        if (RuntimeGate::ingestionEnabled() && (!$secretConfigured || !$pseudonymConfigured)) {
            $healthy = false;
        }

        return [
            'module' => 'CF-05',
            'version' => SMAI_VERSION,
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'contract_version' => SMAI_CONTRACT_VERSION,
            'runtime_state' => RuntimeGate::state(),
            'activation_approved' => RuntimeGate::activationApproved(),
            'ingestion_enabled' => RuntimeGate::ingestionEnabled(),
            'query_enabled' => RuntimeGate::queryEnabled(),
            'ingestion_secret' => $secretConfigured ? 'configured' : 'missing',
            'pseudonym_key' => $pseudonymConfigured ? 'configured' : 'missing',
            'tables' => $tables,
            'status' => $healthy ? 'healthy_within_declared_scope' : 'degraded_or_unavailable',
            'production_complete' => false,
            'generated_at' => gmdate('c'),
        ];
    }
}
