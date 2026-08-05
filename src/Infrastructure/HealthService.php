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
        $exportConfigured = defined('SMAI_EXPORT_KEY') && is_string(SMAI_EXPORT_KEY) && strlen(SMAI_EXPORT_KEY) >= 32;
        if ((RuntimeGate::ingestionEnabled() || RuntimeGate::workerEnabled()) && (!$secretConfigured || !$pseudonymConfigured)) {
            $healthy = false;
        }
        $queue = $this->db->exists('jobs') ? $this->db->wpdb()->get_row(
            "SELECT SUM(state IN ('queued','retrying')) AS pending,SUM(state='dead_letter') AS dead,MIN(CASE WHEN state IN ('queued','retrying') THEN next_run_at END) AS oldest FROM `{$this->db->table('jobs')}`",
            ARRAY_A
        ) : [];
        $quality = $this->db->exists('quality_issues') ? $this->db->wpdb()->get_row(
            "SELECT SUM(state='open') AS open_issues,SUM(state='open' AND severity IN ('high','critical')) AS high_critical FROM `{$this->db->table('quality_issues')}`",
            ARRAY_A
        ) : [];
        return [
            'module' => 'CF-05',
            'version' => SMAI_VERSION,
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'contract_version' => SMAI_CONTRACT_VERSION,
            'runtime_state' => RuntimeGate::state(),
            'activation_approved' => RuntimeGate::activationApproved(),
            'ingestion_enabled' => RuntimeGate::ingestionEnabled(),
            'query_enabled' => RuntimeGate::queryEnabled(),
            'worker_enabled' => RuntimeGate::workerEnabled(),
            'secrets' => [
                'ingestion' => $secretConfigured ? 'configured' : 'missing',
                'pseudonymization' => $pseudonymConfigured ? 'configured' : 'missing',
                'export_encryption' => $exportConfigured ? 'configured' : 'missing',
            ],
            'queue' => is_array($queue) ? [
                'pending' => (int) ($queue['pending'] ?? 0),
                'dead_letter' => (int) ($queue['dead'] ?? 0),
                'oldest_pending_at' => $queue['oldest'] ?? null,
            ] : [],
            'quality' => is_array($quality) ? [
                'open_issues' => (int) ($quality['open_issues'] ?? 0),
                'high_critical' => (int) ($quality['high_critical'] ?? 0),
            ] : [],
            'audit_chain' => $this->db->exists('audit_log') ? (new AuditVerifier($this->db))->verify(10000) : ['status' => 'unavailable'],
            'tables' => $tables,
            'status' => $healthy ? 'healthy_within_declared_scope' : 'degraded_or_unavailable',
            'production_complete' => false,
            'generated_at' => gmdate('c'),
        ];
    }
}
