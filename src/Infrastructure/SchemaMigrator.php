<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class SchemaMigrator
{
    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'smai_';
        $sql = [];

        $sql[] = "CREATE TABLE {$p}event_schemas (
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

        $sql[] = "CREATE TABLE {$p}events (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            event_name varchar(190) NOT NULL,
            event_version varchar(32) NOT NULL,
            source_module varchar(100) NOT NULL,
            source_version varchar(32) NOT NULL,
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
            is_late tinyint unsigned NOT NULL DEFAULT 0,
            correction_of_event_id char(36) NULL,
            expires_at datetime NULL,
            processed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY contract (event_name,event_version),
            KEY occurred_at (occurred_at),
            KEY deletion_key (deletion_key),
            KEY process_queue (processed_at,id),
            KEY expires_at (expires_at),
            KEY correction_of_event_id (correction_of_event_id),
            KEY source_sequence (source_module,source_sequence)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}quarantine (
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

        $sql[] = "CREATE TABLE {$p}metrics (
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

        $sql[] = "CREATE TABLE {$p}metric_snapshots (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            metric_id varchar(190) NOT NULL,
            metric_version varchar(32) NOT NULL,
            build_uuid char(36) NULL,
            window_start datetime NOT NULL,
            window_end datetime NOT NULL,
            dimensions_hash char(64) NOT NULL,
            snapshot_revision int unsigned NOT NULL DEFAULT 1,
            supersedes_snapshot_id bigint unsigned NULL,
            dimensions_json longtext NOT NULL,
            value_decimal decimal(30,10) NULL,
            numerator_decimal decimal(30,10) NULL,
            denominator_decimal decimal(30,10) NULL,
            cohort_size bigint unsigned NOT NULL DEFAULT 0,
            quality_status varchar(32) NOT NULL DEFAULT 'unknown',
            data_through datetime NULL,
            coverage_decimal decimal(12,6) NULL,
            uncertainty_json longtext NULL,
            caveats_json longtext NULL,
            snapshot_hash char(64) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'published',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY snapshot_identity (metric_id,metric_version,window_start,window_end,dimensions_hash,snapshot_revision),
            KEY metric_window (metric_id,metric_version,window_end),
            KEY quality_status (quality_status),
            KEY build_uuid (build_uuid),
            KEY supersedes_snapshot_id (supersedes_snapshot_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}access_projects (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            project_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            purpose text NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'requested',
            owner_user_id bigint unsigned NOT NULL,
            datasets_json longtext NOT NULL,
            fields_json longtext NOT NULL,
            training_confirmed tinyint unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            approved_by bigint unsigned NULL,
            approved_at datetime NULL,
            reviewed_at datetime NULL,
            revoked_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY project_uuid (project_uuid),
            KEY owner_state (owner_user_id,state),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}audit_log (
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

        $sql[] = "CREATE TABLE {$p}audit_state (
            id tinyint unsigned NOT NULL,
            last_hash char(64) NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}governance_transitions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(32) NOT NULL,
            object_id bigint unsigned NOT NULL,
            from_state varchar(32) NOT NULL,
            to_state varchar(32) NOT NULL,
            actor_user_id bigint unsigned NOT NULL,
            reason varchar(500) NOT NULL,
            idempotency_key char(64) NULL,
            policy_version varchar(64) NULL,
            trace_id varchar(100) NULL,
            row_version_from bigint unsigned NOT NULL,
            row_version_to bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transition_idempotency (object_type,object_id,idempotency_key),
            KEY object_history (object_type,object_id,id),
            KEY actor_time (actor_user_id,created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}deletion_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_uuid char(36) NULL,
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

        $sql[] = "CREATE TABLE {$p}deletion_reconciliations (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            deletion_job_id bigint unsigned NOT NULL,
            store_name varchar(100) NOT NULL,
            eligible_before bigint unsigned NOT NULL DEFAULT 0,
            eligible_after bigint unsigned NOT NULL DEFAULT 0,
            state varchar(32) NOT NULL,
            evidence_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_store (deletion_job_id,store_name),
            KEY state (state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}quality_issues (
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

        $sql[] = "CREATE TABLE {$p}exports (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            export_uuid char(36) NOT NULL,
            project_uuid char(36) NOT NULL,
            requester_user_id bigint unsigned NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'requested',
            purpose varchar(190) NOT NULL,
            definition_json longtext NOT NULL,
            columns_json longtext NULL,
            row_limit int unsigned NOT NULL DEFAULT 10000,
            file_path_hash char(64) NULL,
            file_sha256 char(64) NULL,
            token_hash char(64) NULL,
            encryption_context varchar(190) NULL,
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

        $sql[] = "CREATE TABLE {$p}export_payloads (
            export_uuid char(36) NOT NULL,
            encrypted_payload longblob NOT NULL,
            payload_size bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (export_uuid)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}ingestion_nonces (
            nonce_hash char(64) NOT NULL,
            service varchar(100) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (nonce_hash),
            KEY expiry (expires_at),
            KEY service_time (service,created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}datasets (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            dataset_id varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            name varchar(190) NOT NULL,
            owner_module varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            grain varchar(100) NOT NULL,
            definition_json longtext NOT NULL,
            definition_hash char(64) NOT NULL,
            privacy_class varchar(16) NOT NULL,
            retention_days int unsigned NOT NULL DEFAULT 90,
            region_code varchar(32) NOT NULL DEFAULT 'unspecified',
            provider_id varchar(100) NOT NULL DEFAULT 'local',
            quality_status varchar(32) NOT NULL DEFAULT 'unknown',
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dataset_contract (dataset_id,dataset_version),
            KEY state (state),
            KEY owner_module (owner_module),
            KEY provider_region (provider_id,region_code)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}dataset_builds (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            build_uuid char(36) NOT NULL,
            dataset_id varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'planned',
            is_active tinyint unsigned NOT NULL DEFAULT 0,
            source_start datetime NULL,
            source_end datetime NULL,
            row_count bigint unsigned NOT NULL DEFAULT 0,
            build_hash char(64) NULL,
            checkpoint_json longtext NULL,
            comparison_json longtext NULL,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            activated_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY build_uuid (build_uuid),
            KEY dataset_state (dataset_id,dataset_version,state),
            KEY active_build (dataset_id,dataset_version,is_active)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}dataset_rows (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            build_uuid char(36) NOT NULL,
            dataset_id varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            source_event_id char(36) NULL,
            source_object_ref char(64) NULL,
            deletion_key char(64) NULL,
            effective_from datetime NOT NULL,
            effective_to datetime NULL,
            is_current tinyint unsigned NOT NULL DEFAULT 1,
            row_json longtext NOT NULL,
            row_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY build_row (build_uuid,row_hash),
            KEY dataset_effective (dataset_id,dataset_version,effective_from),
            KEY deletion_key (deletion_key),
            KEY source_event_id (source_event_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}lineage_edges (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            from_type varchar(32) NOT NULL,
            from_ref varchar(190) NOT NULL,
            from_version varchar(64) NULL,
            to_type varchar(32) NOT NULL,
            to_ref varchar(190) NOT NULL,
            to_version varchar(64) NULL,
            code_sha char(64) NULL,
            job_uuid char(36) NULL,
            owner_module varchar(100) NOT NULL,
            edge_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY edge_hash (edge_hash),
            KEY from_ref (from_type,from_ref),
            KEY to_ref (to_type,to_ref)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}quality_rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            rule_id varchar(190) NOT NULL,
            rule_version varchar(32) NOT NULL,
            dataset_ref varchar(190) NOT NULL,
            rule_type varchar(64) NOT NULL,
            severity varchar(16) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            config_json longtext NOT NULL,
            config_hash char(64) NOT NULL,
            owner_user_id bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY rule_contract (rule_id,rule_version),
            KEY dataset_state (dataset_ref,state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}quality_results (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL,
            rule_id varchar(190) NOT NULL,
            rule_version varchar(32) NOT NULL,
            dataset_ref varchar(190) NOT NULL,
            build_uuid char(36) NULL,
            status varchar(32) NOT NULL,
            observed_decimal decimal(30,10) NULL,
            threshold_decimal decimal(30,10) NULL,
            evidence_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY run_uuid (run_uuid),
            KEY dataset_status (dataset_ref,status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_uuid char(36) NOT NULL,
            job_type varchar(100) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'queued',
            payload_json longtext NOT NULL,
            result_json longtext NULL,
            idempotency_key char(64) NOT NULL,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            max_attempts smallint unsigned NOT NULL DEFAULT 5,
            lease_owner varchar(100) NULL,
            lease_until datetime NULL,
            next_run_at datetime NOT NULL,
            error_code varchar(100) NULL,
            error_message varchar(500) NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY queue (state,next_run_at,lease_until)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}checkpoints (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            stream_ref varchar(190) NOT NULL,
            consumer_ref varchar(190) NOT NULL,
            contract_version varchar(64) NOT NULL,
            watermark_at datetime NULL,
            source_sequence bigint unsigned NULL,
            checkpoint_json longtext NOT NULL,
            checkpoint_hash char(64) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY stream_consumer (stream_ref,consumer_ref),
            KEY watermark_at (watermark_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}backfills (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            backfill_uuid char(36) NOT NULL,
            dataset_id varchar(190) NOT NULL,
            dataset_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'planned',
            date_start datetime NOT NULL,
            date_end datetime NOT NULL,
            definition_json longtext NOT NULL,
            dry_run_json longtext NULL,
            build_uuid char(36) NULL,
            previous_build_uuid char(36) NULL,
            comparison_json longtext NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            requested_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            activated_by bigint unsigned NULL,
            activated_at datetime NULL,
            rolled_back_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY backfill_uuid (backfill_uuid),
            KEY dataset_state (dataset_id,dataset_version,state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}providers (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(100) NOT NULL,
            provider_version varchar(64) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            region_code varchar(32) NOT NULL,
            capabilities_json longtext NOT NULL,
            security_json longtext NOT NULL,
            retention_json longtext NOT NULL,
            exit_json longtext NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY provider_contract (provider_id,provider_version),
            KEY state_region (state,region_code)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}reports (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            report_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            owner_user_id bigint unsigned NOT NULL,
            project_uuid char(36) NOT NULL,
            definition_json longtext NOT NULL,
            schedule_rrule varchar(500) NULL,
            next_run_at datetime NULL,
            expires_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY report_uuid (report_uuid),
            KEY project_state (project_uuid,state),
            KEY next_run (state,next_run_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}report_deliveries (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            delivery_uuid char(36) NOT NULL,
            report_uuid char(36) NOT NULL,
            run_key char(64) NULL,
            recipient_hash char(64) NOT NULL,
            channel varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'queued',
            token_hash char(64) NULL,
            bundle_json longtext NULL,
            expires_at datetime NOT NULL,
            sent_at datetime NULL,
            revoked_at datetime NULL,
            error_code varchar(100) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY delivery_uuid (delivery_uuid),
            UNIQUE KEY run_key (run_key),
            KEY report_state (report_uuid,state),
            KEY expiry (expires_at,revoked_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}narratives (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            insight_uuid char(36) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            title varchar(190) NOT NULL,
            observation text NOT NULL,
            inference text NULL,
            recommendation text NULL,
            citations_json longtext NOT NULL,
            ai_assisted tinyint unsigned NOT NULL DEFAULT 0,
            author_user_id bigint unsigned NOT NULL,
            reviewer_user_id bigint unsigned NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY insight_uuid (insight_uuid),
            KEY state (state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}experiments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            experiment_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'proposed',
            hypothesis text NOT NULL,
            owner_user_id bigint unsigned NOT NULL,
            assignment_owner varchar(100) NOT NULL,
            audience_json longtext NOT NULL,
            metrics_json longtext NOT NULL,
            guardrails_json longtext NOT NULL,
            design_json longtext NOT NULL,
            privacy_class varchar(16) NOT NULL,
            enhanced_review tinyint unsigned NOT NULL DEFAULT 0,
            starts_at datetime NULL,
            ends_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            approved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY experiment_uuid (experiment_uuid),
            KEY state_time (state,starts_at,ends_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}experiment_facts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            experiment_uuid char(36) NOT NULL,
            assignment_event_id char(36) NOT NULL,
            subject_ref char(64) NOT NULL,
            variant_key varchar(100) NOT NULL,
            assignment_owner varchar(100) NOT NULL,
            occurred_at datetime NOT NULL,
            fact_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY assignment_event_id (assignment_event_id),
            KEY experiment_variant (experiment_uuid,variant_key),
            KEY subject_ref (subject_ref)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}experiment_analyses (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            analysis_uuid char(36) NOT NULL,
            experiment_uuid char(36) NOT NULL,
            analysis_version varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            result_json longtext NOT NULL,
            result_hash char(64) NOT NULL,
            deviations_json longtext NULL,
            analyst_user_id bigint unsigned NOT NULL,
            reviewer_user_id bigint unsigned NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            published_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY analysis_contract (experiment_uuid,analysis_version),
            KEY state (state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}decision_records (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            decision_uuid char(36) NOT NULL,
            subject_type varchar(32) NOT NULL,
            subject_ref varchar(190) NOT NULL,
            evidence_json longtext NOT NULL,
            alternatives_json longtext NOT NULL,
            risks_json longtext NOT NULL,
            action_owner varchar(100) NOT NULL,
            decision_text text NOT NULL,
            review_at datetime NULL,
            outcome_json longtext NULL,
            approver_user_id bigint unsigned NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY decision_uuid (decision_uuid),
            KEY subject (subject_type,subject_ref)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}restore_points (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            restore_uuid char(36) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'recorded',
            code_sha char(64) NOT NULL,
            schema_version varchar(64) NOT NULL,
            catalog_hash char(64) NOT NULL,
            checkpoints_json longtext NOT NULL,
            deletion_floor_id bigint unsigned NOT NULL DEFAULT 0,
            access_floor_id bigint unsigned NOT NULL DEFAULT 0,
            evidence_json longtext NOT NULL,
            recorded_by bigint unsigned NOT NULL,
            verified_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            verified_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY restore_uuid (restore_uuid),
            KEY state (state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}rate_limits (
            limit_key char(64) NOT NULL,
            bucket_start datetime NOT NULL,
            count_value int unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            PRIMARY KEY (limit_key,bucket_start),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}idempotency_keys (
            idempotency_key char(64) NOT NULL,
            scope varchar(100) NOT NULL,
            actor_ref varchar(100) NOT NULL,
            request_hash char(64) NOT NULL,
            response_json longtext NULL,
            status_code smallint unsigned NULL,
            state varchar(32) NOT NULL DEFAULT 'started',
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (idempotency_key),
            KEY scope_actor (scope,actor_ref),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}dashboard_definitions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            dashboard_id varchar(190) NOT NULL,
            dashboard_version varchar(32) NOT NULL,
            name varchar(190) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            owner_user_id bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            project_uuid char(36) NOT NULL,
            audience_json longtext NOT NULL,
            definition_json longtext NOT NULL,
            definition_hash char(64) NOT NULL,
            expires_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dashboard_contract (dashboard_id,dashboard_version),
            KEY project_state (project_uuid,state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}dashboard_widgets (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            dashboard_id varchar(190) NOT NULL,
            dashboard_version varchar(32) NOT NULL,
            widget_key varchar(100) NOT NULL,
            metric_id varchar(190) NOT NULL,
            metric_version varchar(32) NOT NULL,
            config_json longtext NOT NULL,
            position_order int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dashboard_widget (dashboard_id,dashboard_version,widget_key),
            KEY metric (metric_id,metric_version)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$p}audit_state (id,last_hash,row_version,updated_at) VALUES (1,%s,1,%s)",
            str_repeat('0', 64),
            gmdate('Y-m-d H:i:s')
        ));

        update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
    }
}
