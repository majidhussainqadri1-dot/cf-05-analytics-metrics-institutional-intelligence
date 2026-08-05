<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(plugin_basename(SMAI_FILE));
            wp_die(esc_html__('CF-05 requires PHP 8.1 or newer.', 'sabri-analytics-institutional-intelligence'));
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'smai_';

        $schemas = [];
        $schemas[] = "CREATE TABLE {$prefix}event_schemas (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_name varchar(190) NOT NULL,
            event_version varchar(32) NOT NULL,
            owner_module varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            purpose varchar(190) NOT NULL,
            privacy_class varchar(16) NOT NULL,
            retention_days int unsigned NOT NULL DEFAULT 30,
            deletion_key_field varchar(100) NOT NULL,
            schema_json longtext NOT NULL,
            schema_hash char(64) NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_contract (event_name,event_version),
            KEY state (state),
            KEY owner_module (owner_module)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}events (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            event_name varchar(190) NOT NULL,
            event_version varchar(32) NOT NULL,
            source_module varchar(100) NOT NULL,
            source_environment varchar(32) NOT NULL,
            source_sequence bigint unsigned NULL,
            occurred_at datetime NOT NULL,
            recorded_at datetime NOT NULL,
            actor_ref char(64) NULL,
            object_ref char(64) NULL,
            deletion_key char(64) NULL,
            purpose varchar(190) NOT NULL,
            consent_version varchar(64) NULL,
            policy_version varchar(64) NULL,
            trace_id varchar(100) NULL,
            properties_json longtext NOT NULL,
            payload_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY contract (event_name,event_version),
            KEY occurred_at (occurred_at),
            KEY deletion_key (deletion_key),
            KEY source_sequence (source_module,source_sequence)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}quarantine (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(64) NULL,
            event_name varchar(190) NULL,
            event_version varchar(32) NULL,
            source_module varchar(100) NULL,
            reason_code varchar(100) NOT NULL,
            reason_detail varchar(255) NOT NULL,
            redacted_sample longtext NULL,
            payload_hash char(64) NULL,
            status varchar(32) NOT NULL DEFAULT 'open',
            retry_count smallint unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY status_retry (status,next_retry_at),
            KEY contract (event_name,event_version)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}metrics (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            metric_id varchar(190) NOT NULL,
            metric_version varchar(32) NOT NULL,
            name varchar(190) NOT NULL,
            owner_module varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            business_question text NOT NULL,
            definition_json longtext NOT NULL,
            definition_hash char(64) NOT NULL,
            privacy_class varchar(16) NOT NULL,
            minimum_cohort int unsigned NOT NULL DEFAULT 20,
            effective_from datetime NULL,
            effective_to datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY metric_contract (metric_id,metric_version),
            KEY state (state),
            KEY owner_module (owner_module)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}metric_snapshots (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            metric_id varchar(190) NOT NULL,
            metric_version varchar(32) NOT NULL,
            window_start datetime NOT NULL,
            window_end datetime NOT NULL,
            dimensions_hash char(64) NOT NULL,
            dimensions_json longtext NOT NULL,
            value_decimal decimal(30,10) NULL,
            numerator_decimal decimal(30,10) NULL,
            denominator_decimal decimal(30,10) NULL,
            cohort_size bigint unsigned NOT NULL DEFAULT 0,
            quality_status varchar(32) NOT NULL DEFAULT 'unknown',
            data_through datetime NULL,
            caveats_json longtext NULL,
            snapshot_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY snapshot_identity (metric_id,metric_version,window_start,window_end,dimensions_hash),
            KEY metric_window (metric_id,metric_version,window_end),
            KEY quality_status (quality_status)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}access_projects (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            project_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            purpose text NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'requested',
            owner_user_id bigint unsigned NOT NULL,
            datasets_json longtext NOT NULL,
            fields_json longtext NOT NULL,
            expires_at datetime NOT NULL,
            approved_by bigint unsigned NULL,
            approved_at datetime NULL,
            revoked_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY project_uuid (project_uuid),
            KEY owner_state (owner_user_id,state),
            KEY expires_at (expires_at)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}audit_log (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            actor_user_id bigint unsigned NULL,
            actor_type varchar(32) NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(100) NOT NULL,
            object_ref varchar(190) NULL,
            purpose varchar(190) NULL,
            result varchar(32) NOT NULL,
            trace_id varchar(100) NULL,
            context_json longtext NULL,
            previous_hash char(64) NOT NULL,
            record_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY actor_time (actor_user_id,created_at),
            KEY object_time (object_type,object_ref,created_at),
            KEY action_result (action,result)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}audit_state (
            id tinyint unsigned NOT NULL,
            last_hash char(64) NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}governance_transitions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(32) NOT NULL,
            object_id bigint unsigned NOT NULL,
            from_state varchar(32) NOT NULL,
            to_state varchar(32) NOT NULL,
            actor_user_id bigint unsigned NOT NULL,
            reason varchar(500) NOT NULL,
            row_version_from bigint unsigned NOT NULL,
            row_version_to bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY object_history (object_type,object_id,id),
            KEY actor_time (actor_user_id,created_at)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}deletion_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            deletion_key char(64) NOT NULL,
            source_module varchar(100) NOT NULL,
            source_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'requested',
            scope_json longtext NOT NULL,
            result_json longtext NULL,
            retry_count smallint unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL,
            requested_at datetime NOT NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY deletion_request (deletion_key,source_module,source_version),
            KEY state_retry (state,next_retry_at)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}quality_issues (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            issue_uuid char(36) NOT NULL,
            dataset_ref varchar(190) NOT NULL,
            rule_id varchar(190) NOT NULL,
            severity varchar(16) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'open',
            summary varchar(255) NOT NULL,
            evidence_json longtext NULL,
            owner_user_id bigint unsigned NULL,
            detected_at datetime NOT NULL,
            resolved_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY issue_uuid (issue_uuid),
            KEY state_severity (state,severity),
            KEY dataset_rule (dataset_ref,rule_id)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}ingestion_nonces (
            nonce_hash char(64) NOT NULL,
            service varchar(100) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (nonce_hash),
            KEY expiry (expires_at),
            KEY service_time (service,created_at)
        ) {$charset};";

        $schemas[] = "CREATE TABLE {$prefix}exports (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            export_uuid char(36) NOT NULL,
            project_uuid char(36) NOT NULL,
            requester_user_id bigint unsigned NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'requested',
            purpose varchar(190) NOT NULL,
            definition_json longtext NOT NULL,
            file_path_hash char(64) NULL,
            file_sha256 char(64) NULL,
            expires_at datetime NOT NULL,
            revoked_at datetime NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY export_uuid (export_uuid),
            KEY project_state (project_uuid,state),
            KEY expiry (expires_at,revoked_at)
        ) {$charset};";

        foreach ($schemas as $sql) {
            dbDelta($sql);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$prefix}audit_state (id,last_hash,row_version,updated_at) VALUES (1,%s,1,%s)",
            str_repeat('0', 64),
            gmdate('Y-m-d H:i:s')
        ));

        update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
        add_option('smai_runtime_state', 'foundation_disabled', '', false);
        add_option('smai_activation_approved', '0', '', false);
        add_option('smai_activation_evidence_hash', '', '', false);
        add_option('smai_minimum_cohort', '20', '', false);
        add_option('smai_raw_retention_days', '30', '', false);
        add_option('smai_quarantine_retention_days', '14', '', false);

        self::addCapabilities();

        if (!wp_next_scheduled('smai_daily_retention')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'smai_daily_retention');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('smai_daily_retention');
    }

    private static function addCapabilities(): void
    {
        $caps = [
            'smai_view_insights',
            'smai_manage_catalog',
            'smai_approve_catalog',
            'smai_manage_quality',
            'smai_manage_access',
            'smai_ingest_events',
            'smai_query_metrics',
            'smai_export_metrics',
            'smai_manage_experiments',
            'smai_audit',
        ];

        foreach (['administrator'] as $roleName) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }
}
