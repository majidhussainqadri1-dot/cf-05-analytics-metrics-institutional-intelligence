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
        add_filter('sabri_file26_analytics_providers', [$this, 'appendMetricProvider']);
        add_filter('sabri_file24_assurance_evidence', [$this, 'appendAssurance']);
        add_filter('sabri_file25_component_providers', [$this, 'appendVisualProvider']);
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
