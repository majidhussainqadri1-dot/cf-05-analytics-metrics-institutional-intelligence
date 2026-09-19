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
        $degradationReasons = [];

        foreach ($this->db->tables() as $name => $table) {
            $exists = $this->db->exists($name);
            if (!$exists) {
                $healthy = false;
                $degradationReasons[] = 'missing_table:' . $name;
            }
            $tables[$name] = [
                'status' => $exists ? 'available' : 'missing',
                'count' => $includeCounts && $exists ? $this->db->count($name) : null,
            ];
        }

        $schemaReady = RuntimeGate::schemaReady();
        $migrationError = get_option('smai_schema_migration_error', null);
        if (!$schemaReady) {
            $healthy = false;
            $degradationReasons[] = 'schema_not_ready';
        }
        if ((is_string($migrationError) && trim($migrationError) !== '')
            || (is_array($migrationError) && $migrationError !== [])
            || is_object($migrationError)) {
            $healthy = false;
            $degradationReasons[] = 'schema_migration_error';
        }

        $secretConfigured = defined('SMAI_INGESTION_SECRET') && is_string(SMAI_INGESTION_SECRET) && strlen(SMAI_INGESTION_SECRET) >= 32;
        $pseudonymConfigured = defined('SMAI_PSEUDONYM_KEY') && is_string(SMAI_PSEUDONYM_KEY) && strlen(SMAI_PSEUDONYM_KEY) >= 32;
        $exportConfigured = defined('SMAI_EXPORT_KEY') && is_string(SMAI_EXPORT_KEY) && strlen(SMAI_EXPORT_KEY) >= 32;
        $privateConfigurationReady = RuntimeGate::privateConfigurationReady();
        if (in_array(RuntimeGate::state(), [RuntimeGate::STAGING_ACTIVE, RuntimeGate::PRODUCTION_ACTIVE], true) && !$privateConfigurationReady) {
            $healthy = false;
            $degradationReasons[] = 'runtime_private_configuration_missing';
        }

        $queue = $this->db->exists('jobs') ? $this->db->wpdb()->get_row(
            "SELECT SUM(state IN ('queued','retrying')) AS pending,SUM(state='dead_letter') AS dead,MIN(CASE WHEN state IN ('queued','retrying') THEN next_run_at END) AS oldest FROM `{$this->db->table('jobs')}`",
            ARRAY_A
        ) : [];
        $quality = $this->db->exists('quality_issues') ? $this->db->wpdb()->get_row(
            "SELECT SUM(state='open') AS open_issues,SUM(state='open' AND severity IN ('high','critical')) AS high_critical FROM `{$this->db->table('quality_issues')}`",
            ARRAY_A
        ) : [];

        $deletionSloHours = max(1, min(168, (int) get_option('smai_deletion_slo_hours', 24)));
        $overdueDeletionJobs = 0;
        if ($this->db->exists('deletion_jobs')) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($deletionSloHours * HOUR_IN_SECONDS));
            $overdueDeletionJobs = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                "SELECT COUNT(*) FROM `{$this->db->table('deletion_jobs')}` WHERE state NOT IN ('completed','cancelled') AND requested_at < %s",
                $cutoff
            ));
            if ($overdueDeletionJobs > 0) {
                $healthy = false;
                $degradationReasons[] = 'overdue_deletion_jobs';
            }
        }

        if (is_array($queue) && (int) ($queue['dead'] ?? 0) > 0) { $healthy = false; $degradationReasons[] = 'dead_letter_jobs_present'; }
        if (is_array($quality) && (int) ($quality['high_critical'] ?? 0) > 0) {
            $healthy = false;
            $degradationReasons[] = 'high_or_critical_quality_issue';
        }

        $auditChain=$this->db->exists('audit_log')&&$this->db->exists('audit_state')?(new AuditVerifier($this->db))->verify(10000):['status'=>'unavailable'];
        if (($auditChain['status']??'unavailable')!=='verified') { $healthy=false; $degradationReasons[]='audit_chain_unverified'; }
        $degradationReasons = array_values(array_unique($degradationReasons));

        return [
            'module' => 'CF-05',
            'version' => SMAI_VERSION,
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'expected_schema_version' => defined('SMAI_SCHEMA_VERSION') ? SMAI_SCHEMA_VERSION : 'undefined',
            'schema_ready' => $schemaReady,
            'schema_migration_error' => $migrationError,
            'contract_version' => SMAI_CONTRACT_VERSION,
            'runtime_state' => RuntimeGate::state(),
            'activation_approved' => RuntimeGate::activationApproved(),
            'ingestion_enabled' => RuntimeGate::ingestionEnabled(),
            'query_enabled' => RuntimeGate::queryEnabled(),
            'worker_enabled' => RuntimeGate::workerEnabled(),
            'private_configuration_ready' => $privateConfigurationReady,
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
            'deletion_slo_hours' => $deletionSloHours,
            'overdue_deletion_jobs' => $overdueDeletionJobs,
            'degradation_reasons' => $degradationReasons,
            'audit_chain' => $auditChain,
            'tables' => $tables,
            'status' => $healthy ? 'healthy_within_declared_scope' : 'degraded_or_unavailable',
            'production_complete' => false,
            'generated_at' => gmdate('c'),
        ];
    }
}
