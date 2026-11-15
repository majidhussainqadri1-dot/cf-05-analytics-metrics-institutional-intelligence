<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class IntegrationRegistry
{
    public function register(): void
    {
        add_action('init', [$this, 'announce'], 20);
        add_filter('sabri_file20_module_manifests', [$this, 'appendManifest']);
        add_filter('sabri_file23_analytics_providers', [$this, 'appendMetricProvider']);
        add_action('spdb/register_adapters', [$this, 'registerNativeFile23Adapter'], 10, 1);
        add_filter('sabri_file26_analytics_providers', [$this, 'appendMetricProvider']);
        add_filter('sabri_file24_assurance_evidence', [$this, 'appendAssurance']);
        add_filter('spcrc/module_manifests', [$this, 'appendNativeFile24Manifest'], 10, 1);
        add_filter('sabri_file25_component_providers', [$this, 'appendVisualProvider']);
    }

    /**
     * File 23's real adapter registry is the owner contract. The historical
     * sabri_file23_analytics_providers filter is metadata, not registration.
     * Missing or incompatible File 23 interfaces cause a safe no-op.
     */
    public function registerNativeFile23Adapter(mixed $registry): void
    {
        if (!class_exists('\SPDB_Adapter_Registry')
            || !$registry instanceof \SPDB_Adapter_Registry
            || !interface_exists('\SPDB_Provider_Adapter')
            || !interface_exists('\SPDB_Analytics_Provider')
            || !isset($GLOBALS['wpdb'])
            || !$GLOBALS['wpdb'] instanceof \wpdb) {
            return;
        }
        // The File 23 registry enforces its own exact adapter contract and
        // environment acceptance independently of CF-05.
        $registry->register(new \Sabri\AnalyticsIntelligence\Integration\File23AnalyticsAdapter(
            new Database($GLOBALS['wpdb'])
        ));
    }

    public function announce(): void
    {
        do_action('sabri_platform_register_module', $this->manifest());
    }

    public function appendManifest(mixed $manifests): array
    {
        $manifests = is_array($manifests) ? $manifests : [];
        $manifests['cf-05'] = $this->manifest();
        return $manifests;
    }

    public function appendMetricProvider(mixed $providers): array
    {
        $providers = is_array($providers) ? $providers : [];
        $providers['cf-05'] = [
            'contract_version' => SMAI_CONTRACT_VERSION,
            'rest_namespace' => 'sabri-analytics/v1',
            'capability' => 'smai_query_metrics',
            'authority' => 'aggregate_metrics_only',
            'mutates_consumer' => false,
        ];
        return $providers;
    }

    /**
     * File 24 currently consumes spcrc/module_manifests, NOT the older
     * sabri_file24_assurance_evidence metadata filter. A source-candidate
     * manifest deliberately remains "unassessed" until independent external
     * release/security evidence exists; it cannot self-award operational status.
     */
    public function appendNativeFile24Manifest(mixed $manifests): array
    {
        $manifests = is_array($manifests) ? $manifests : [];
        $tables = [];
        if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof \wpdb) {
            $tables = array_keys((new Database($GLOBALS['wpdb']))->tables());
        }
        $manifests[] = [
            'module_key' => 'cf-05',
            'name' => 'Sabri Analytics, Metrics and Institutional Intelligence',
            'version' => SMAI_VERSION,
            'owner' => 'CF 05',
            'posture' => 'unassessed',
            'data_classes' => [
                'C1 Internal',
                'C2 Pseudonymous Analytics',
                'C5 Audit Evidence References',
            ],
            // CF-05 is never an anonymous public metric API.
            'public_routes' => [],
            'private_routes' => [
                '/wp-json/sabri-analytics/v1/health',
                '/wp-json/sabri-analytics/v1/events',
                '/wp-json/sabri-analytics/v1/exports',
                '/wp-json/sabri-analytics/v1/deletions',
            ],
            'tables' => $tables,
            'files' => [],
            'capabilities' => [
                'smai_view_insights',
                'smai_query_metrics',
                'smai_manage_catalog',
                'smai_manage_quality',
                'smai_manage_access',
                'smai_manage_deletions',
                'smai_export_metrics',
                'smai_restore',
            ],
            'external_vendors' => [],
            'secret_classes' => [
                'ingestion-secret-reference',
                'pseudonym-key-reference',
                'export-key-reference',
            ],
            // File 24 supplies only a WordPress user ID; it cannot derive
            // CF-05's source-owner deletion key. Claiming deletion here would
            // misdirect real privacy requests to an unproven handler.
            'privacy_operations' => [],
            'exporters' => ['aggregate-export'],
            'erasers' => ['deletion-service'],
            'emergency_callbacks' => ['runtime-disable'],
            // No security-test date is invented. File 24 must keep the manifest
            // unassessed while this field is empty.
            'last_security_test' => '',
            'verification_level' => '',
            'contract_version' => '1.2.0',
            'canonical_data_owner' => 'CF 05 derivative analytics only',
            'canonical_action_owner' => 'Native domain owners control canonical actions',
            'evidence_source' => 'source:cf05-v123-candidate',
            'degraded_behavior' => 'No derivative query while schema or approval is unknown; no permissive fallback.',
            'release_gate' => 'Independent staging security and privacy review, restore test and production parity pending',
        ];
        return $manifests;
    }

    public function appendAssurance(mixed $evidence): array
    {
        $evidence = is_array($evidence) ? $evidence : [];
        $evidence['cf-05'] = [
            'module_version' => SMAI_VERSION,
            'schema_version' => (string) get_option('smai_schema_version', 'unknown'),
            'runtime_state' => RuntimeGate::state(),
            'activation_approved' => RuntimeGate::activationApproved(),
            'native_controls_preserved' => true,
            'raw_sensitive_domains_excluded' => true,
            'generated_at' => gmdate('c'),
        ];
        return $evidence;
    }

    public function appendVisualProvider(mixed $providers): array
    {
        $providers = is_array($providers) ? $providers : [];
        $providers['cf-05-insights'] = [
            'shortcode' => 'sabri_institutional_insights',
            'semantic_owner' => 'cf-05',
            'visual_owner' => 'file-25',
            'shell_owner' => 'file-20',
        ];
        return $providers;
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return [
            'module_id' => 'CF-05',
            'name' => 'Analytics, Metrics and Institutional Intelligence',
            'version' => SMAI_VERSION,
            'schema_version' => SMAI_SCHEMA_VERSION,
            'contract_version' => SMAI_CONTRACT_VERSION,
            'runtime_state' => RuntimeGate::state(),
            'activation_approved' => RuntimeGate::activationApproved(),
            'truth_status' => [
                'coded' => true,
                'staging_accepted' => false,
                'live_deployed' => false,
                'operational' => false,
            ],
            'routes' => [
                '/insights',
                '/insights/{domain}',
                '/wp-json/sabri-analytics/v1/metrics/{metric_id}',
                '/wp-json/sabri-analytics/v1/events',
            ],
            'capabilities' => [
                'smai_view_insights',
                'smai_query_metrics',
                'smai_manage_catalog',
                'smai_manage_quality',
                'smai_manage_access',
            ],
            'canonical_ownership' => [
                'approved event and metric contracts',
                'privacy-safe derivative analytics data',
                'datasets, lineage, quality, snapshots, reports, experiments and decision evidence',
            ],
            'non_ownership' => [
                'identity, roles, domain entities, clinical records, payment ledger, private messages',
                'feed ranking, recommendations, moderation, publication or human decisions',
            ],
        ];
    }
}
