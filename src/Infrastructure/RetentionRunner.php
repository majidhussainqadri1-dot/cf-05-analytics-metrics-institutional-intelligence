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
        $eventDays = max(1, min(365, (int) get_option('smai_raw_retention_days', 30)));
        $quarantineDays = max(1, min(90, (int) get_option('smai_quarantine_retention_days', 14)));

        $events = $this->db->table('events');
        $quarantine = $this->db->table('quarantine');
        $exports = $this->db->table('exports');
        $nonces = $this->db->table('ingestion_nonces');
        $now = gmdate('Y-m-d H:i:s');
        $eventCutoff = gmdate('Y-m-d H:i:s', time() - ($eventDays * DAY_IN_SECONDS));
        $quarantineCutoff = gmdate('Y-m-d H:i:s', time() - ($quarantineDays * DAY_IN_SECONDS));

        $wpdb->query($wpdb->prepare("DELETE FROM `{$events}` WHERE created_at < %s", $eventCutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM `{$quarantine}` WHERE created_at < %s AND status IN ('resolved','discarded')", $quarantineCutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("UPDATE `{$exports}` SET state='expired', updated_at=%s WHERE expires_at < %s AND state IN ('requested','building','ready')", $now, $now));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$nonces}` WHERE expires_at < %s", $now)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
