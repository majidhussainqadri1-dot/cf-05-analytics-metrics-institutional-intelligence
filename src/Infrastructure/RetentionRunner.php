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

        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$this->db->table('events')}` WHERE (expires_at IS NOT NULL AND expires_at<%s) OR (expires_at IS NULL AND created_at<%s)",
            $now,
            $eventCutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM `{$this->db->table('dataset_rows')}` r INNER JOIN `{$this->db->table('datasets')}` d ON d.dataset_id=r.dataset_id AND d.dataset_version=r.dataset_version WHERE r.created_at<DATE_SUB(%s, INTERVAL d.retention_days DAY)",
            $now
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$this->db->table('dataset_rows')}` WHERE created_at<%s AND dataset_id NOT IN (SELECT dataset_id FROM `{$this->db->table('datasets')}`)",
            $modelCutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$this->db->table('quarantine')}` WHERE created_at<%s AND status IN ('resolved','discarded')",
            $quarantineCutoff
        ));
        $expiredExports = $wpdb->get_col($wpdb->prepare(
            "SELECT export_uuid FROM `{$this->db->table('exports')}` WHERE expires_at<%s AND state IN ('requested','building','ready','revoked')",
            $now
        ));
        foreach (is_array($expiredExports) ? $expiredExports : [] as $uuid) {
            $wpdb->delete($this->db->table('export_payloads'), ['export_uuid' => $uuid]);
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$this->db->table('exports')}` SET state='expired',updated_at=%s WHERE expires_at<%s AND state IN ('requested','building','ready','revoked')",
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$this->db->table('report_deliveries')}` SET state='expired',token_hash=NULL,bundle_json=NULL,updated_at=%s WHERE expires_at<%s AND state IN ('queued','ready','sent','revoked')",
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('ingestion_nonces')}` WHERE expires_at<%s", $now));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('rate_limits')}` WHERE expires_at<%s", $now));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('idempotency_keys')}` WHERE expires_at<%s", $now));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$this->db->table('jobs')}` WHERE state='completed' AND completed_at<%s",
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
        ));

        // Future-40 derivative evidence is explicitly retention-bound. Governance
        // configuration and published transparency records are not silently purged.
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('future_runs')}` WHERE created_at<%s", $futureRunCutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('scenario_models')}` WHERE updated_at<%s", $futureScenarioCutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('intelligence_alerts')}` WHERE updated_at<%s", $futureAlertCutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('analytics_incidents')}` WHERE state IN ('resolved','closed') AND updated_at<%s", $futureIncidentCutoff));
        $wpdb->query($wpdb->prepare("UPDATE `{$this->db->table('research_workspaces')}` SET state='expired',updated_at=%s WHERE expires_at IS NOT NULL AND expires_at<%s AND state NOT IN ('expired','revoked')", $now, $now));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->db->table('research_workspaces')}` WHERE state IN ('expired','revoked') AND updated_at<%s", $modelCutoff));
    }
}
