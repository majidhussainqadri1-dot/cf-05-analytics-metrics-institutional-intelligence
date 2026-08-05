<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class SchemaUpgrade
{
    public const VERSION = '1.0.0';

    public static function maybeUpgrade(): void
    {
        if ((string) get_option('smai_schema_version', '') === self::VERSION) {
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix . 'smai_';
        $c = $wpdb->get_charset_collate();
        $sql = [];

        $sql[] = "CREATE TABLE {$p}datasets (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            dataset_id varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            owner_module varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            grain varchar(100) NOT NULL,
            privacy_class varchar(16) NOT NULL,
            region_code varchar(32) NOT NULL DEFAULT 'PK',
            provider_ref varchar(190) NULL,
            schema_json longtext NOT NULL,
            retention_days int unsigned NOT NULL DEFAULT 90,
            lineage_complete tinyint(1) NOT NULL DEFAULT 0,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dataset_contract (dataset_id,dataset_version),
            KEY owner_state (owner_module,state),
            KEY privacy_region (privacy_class,region_code)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}lineage_edges (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            edge_uuid char(36) NOT NULL,
            from_type varchar(32) NOT NULL,
            from_ref varchar(190) NOT NULL,
            from_version varchar(32) NOT NULL,
            to_type varchar(32) NOT NULL,
            to_ref varchar(190) NOT NULL,
            to_version varchar(32) NOT NULL,
            transform_ref varchar(190) NOT NULL,
            code_sha char(40) NULL,
            job_uuid char(36) NULL,
            owner_module varchar(100) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY edge_uuid (edge_uuid),
            KEY target (to_type,to_ref,to_version),
            KEY source (from_type,from_ref,from_version)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}pipeline_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_uuid char(36) NOT NULL,
            job_type varchar(32) NOT NULL,
            dataset_ref varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'planned',
            scope_json longtext NOT NULL,
            checkpoint_json longtext NULL,
            dry_run_json longtext NULL,
            comparison_json longtext NULL,
            idempotency_key char(64) NOT NULL,
            requested_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY state_type (state,job_type),
            KEY dataset_state (dataset_ref,dataset_version,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}quality_rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            rule_id varchar(190) NOT NULL,
            rule_version varchar(32) NOT NULL,
            dataset_ref varchar(190) NOT NULL,
            rule_type varchar(32) NOT NULL,
            severity varchar(16) NOT NULL,
            threshold_json longtext NOT NULL,
            block_publication tinyint(1) NOT NULL DEFAULT 0,
            owner_user_id bigint unsigned NULL,
            state varchar(32) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY rule_contract (rule_id,rule_version),
            KEY dataset_state (dataset_ref,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}scheduled_reports (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            report_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            owner_user_id bigint unsigned NOT NULL,
            project_uuid char(36) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            metrics_json longtext NOT NULL,
            recipients_json longtext NOT NULL,
            cadence varchar(64) NOT NULL,
            secure_link_ttl int unsigned NOT NULL DEFAULT 86400,
            next_run_at datetime NULL,
            last_run_at datetime NULL,
            expires_at datetime NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY report_uuid (report_uuid),
            KEY due (state,next_run_at),
            KEY project_state (project_uuid,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}report_runs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL,
            report_uuid char(36) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'queued',
            metric_versions_json longtext NOT NULL,
            window_json longtext NOT NULL,
            result_manifest_json longtext NULL,
            delivery_json longtext NULL,
            failure_code varchar(100) NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY report_state (report_uuid,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}experiments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            experiment_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            hypothesis text NOT NULL,
            owner_module varchar(100) NOT NULL,
            assignment_authority varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            audience_json longtext NOT NULL,
            primary_metrics_json longtext NOT NULL,
            guardrail_metrics_json longtext NOT NULL,
            design_json longtext NOT NULL,
            privacy_review_json longtext NULL,
            result_json longtext NULL,
            decision_uuid char(36) NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            starts_at datetime NULL,
            ends_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY experiment_uuid (experiment_uuid),
            KEY owner_state (owner_module,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}decision_records (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            decision_uuid char(36) NOT NULL,
            title varchar(190) NOT NULL,
            owner_user_id bigint unsigned NOT NULL,
            approver_user_id bigint unsigned NOT NULL,
            metric_evidence_json longtext NOT NULL,
            alternatives_json longtext NOT NULL,
            risks_json longtext NOT NULL,
            recommendation text NOT NULL,
            approved_action text NOT NULL,
            review_due_at datetime NULL,
            outcome_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY decision_uuid (decision_uuid),
            KEY owner_review (owner_user_id,review_due_at)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}retention_holds (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            hold_uuid char(36) NOT NULL,
            scope_type varchar(32) NOT NULL,
            scope_ref varchar(190) NOT NULL,
            reason varchar(500) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'active',
            approved_by bigint unsigned NOT NULL,
            expires_at datetime NULL,
            released_by bigint unsigned NULL,
            released_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY hold_uuid (hold_uuid),
            KEY scope_state (scope_type,scope_ref,state)
        ) {$c};";

        $sql[] = "CREATE TABLE {$p}provider_registry (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(190) NOT NULL,
            provider_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            region_codes_json longtext NOT NULL,
            privacy_classes_json longtext NOT NULL,
            contract_json longtext NOT NULL,
            credential_ref_hash char(64) NULL,
            exit_plan_json longtext NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY provider_contract (provider_id,provider_version),
            KEY state (state)
        ) {$c};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option('smai_schema_version', self::VERSION, false);
    }
}
