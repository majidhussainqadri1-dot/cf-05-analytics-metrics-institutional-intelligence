<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class RetentionRunner
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function register(): void
    {
        add_action('smai_daily_retention', [$this, 'run']);
    }

    public function run(): void
    {
        $wpdb = $this->db->wpdb();
        $now = $this->db->now();
        $eventDays = max(1, min(365, (int) get_option('smai_raw_retention_days', 30)));
        $modeledDays = max(1, min(730, (int) get_option('smai_modeled_retention_days', 180)));
        $quarantineDays = max(1, min(90, (int) get_option('smai_quarantine_retention_days', 14)));
        $futureRunDays = max(1, min(730, (int) get_option('smai_future_run_retention_days', 180)));
        $futureScenarioDays = max(1, min(730, (int) get_option('smai_future_scenario_retention_days', 365)));
        $futureAlertDays = max(1, min(365, (int) get_option('smai_future_alert_retention_days', 180)));
        $futureIncidentDays = max(1, min(730, (int) get_option('smai_future_incident_retention_days', 365)));
        $eventCutoff = gmdate('Y-m-d H:i:s', time() - $eventDays * DAY_IN_SECONDS);
        $modelCutoff = gmdate('Y-m-d H:i:s', time() - $modeledDays * DAY_IN_SECONDS);
        $quarantineCutoff = gmdate('Y-m-d H:i:s', time() - $quarantineDays * DAY_IN_SECONDS);
        $futureRunCutoff = gmdate('Y-m-d H:i:s', time() - $futureRunDays * DAY_IN_SECONDS);
        $futureScenarioCutoff = gmdate('Y-m-d H:i:s', time() - $futureScenarioDays * DAY_IN_SECONDS);
        $futureAlertCutoff = gmdate('Y-m-d H:i:s', time() - $futureAlertDays * DAY_IN_SECONDS);
        $futureIncidentCutoff = gmdate('Y-m-d H:i:s', time() - $futureIncidentDays * DAY_IN_SECONDS);

        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Retention transaction could not start.'); }
        try {
        $this->mustQuery($wpdb, $wpdb->prepare(
            "DELETE FROM `{$this->db->table('events')}` WHERE (expires_at IS NOT NULL AND expires_at<%s) OR (expires_at IS NULL AND created_at<%s)",
            $now,
            $eventCutoff
        ));
        $this->mustQuery($wpdb, $wpdb->prepare(
            "DELETE r FROM `{$this->db->table('dataset_rows')}` r INNER JOIN `{$this->db->table('datasets')}` d ON d.dataset_id=r.dataset_id AND d.dataset_version=r.dataset_version WHERE r.effective_from<DATE_SUB(%s, INTERVAL d.retention_days DAY)",
            $now
        ));
        $this->mustQuery($wpdb, $wpdb->prepare(
            "DELETE FROM `{$this->db->table('dataset_rows')}` WHERE created_at<%s AND dataset_id NOT IN (SELECT dataset_id FROM `{$this->db->table('datasets')}`)",
            $modelCutoff
        ));
        $this->mustQuery($wpdb, $wpdb->prepare(
            "DELETE FROM `{$this->db->table('quarantine')}` WHERE created_at<%s",
            $quarantineCutoff
        ));
        $expiredExports = $wpdb->get_col($wpdb->prepare(
            "SELECT export_uuid FROM `{$this->db->table('exports')}` WHERE expires_at<%s AND state IN ('requested','building','ready','revoked')",
            $now
        ));
        foreach (is_array($expiredExports) ? $expiredExports : [] as $uuid) {
            if ($wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid]) === false) { throw new \RuntimeException('Retention export payload purge failed.'); }
        }
        $this->mustQuery($wpdb, $wpdb->prepare(
            "UPDATE `{$this->db->table('exports')}` SET state='expired',updated_at=%s WHERE expires_at<%s AND state IN ('requested','building','ready','revoked')",
            $now,
            $now
        ));
        $this->mustQuery($wpdb, $wpdb->prepare(
            "UPDATE `{$this->db->table('report_deliveries')}` SET state='expired',token_hash=NULL,bundle_json=NULL,updated_at=%s WHERE expires_at<%s AND state IN ('queued','ready','sent','revoked')",
            $now,
            $now
        ));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('ingestion_nonces')}` WHERE expires_at<%s", $now));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('rate_limits')}` WHERE expires_at<%s", $now));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('idempotency_keys')}` WHERE expires_at<%s", $now));
        $this->mustQuery($wpdb, $wpdb->prepare(
            "DELETE FROM `{$this->db->table('jobs')}` WHERE state='completed' AND completed_at<%s",
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
        ));

        // Future-40 derivative evidence is explicitly retention-bound. Governance
        // configuration and published transparency records are not silently purged.
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('future_runs')}` WHERE created_at<%s", $futureRunCutoff));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('scenario_models')}` WHERE updated_at<%s", $futureScenarioCutoff));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('intelligence_alerts')}` WHERE updated_at<%s", $futureAlertCutoff));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('analytics_incidents')}` WHERE state IN ('resolved','closed') AND updated_at<%s", $futureIncidentCutoff));
        $this->mustQuery($wpdb, $wpdb->prepare("UPDATE `{$this->db->table('research_workspaces')}` SET state='expired',updated_at=%s WHERE expires_at IS NOT NULL AND expires_at<%s AND state NOT IN ('expired','revoked')", $now, $now));
        $this->mustQuery($wpdb, $wpdb->prepare("DELETE FROM `{$this->db->table('research_workspaces')}` WHERE state IN ('expired','revoked') AND updated_at<%s", $modelCutoff));
        $audit = new AuditLogger($this->db);
        if (!$audit->logInOpenTransaction('analytics_retention_completed','retention_run',gmdate('Y-m-d'),'success',['event_cutoff'=>$eventCutoff,'model_cutoff'=>$modelCutoff,'quarantine_cutoff'=>$quarantineCutoff],'retention',null,null,'system')) { throw new \RuntimeException('Retention audit evidence failed.'); }
        if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Retention commit failed.'); }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('Retention run failed safely.', 0, $error);
        }
    }

    private function mustQuery($wpdb, string $sql): int
    {
        $result = $wpdb->query($sql);
        if ($result === false) { throw new \RuntimeException('Retention database operation failed.'); }
        return (int) $result;
    }
}
