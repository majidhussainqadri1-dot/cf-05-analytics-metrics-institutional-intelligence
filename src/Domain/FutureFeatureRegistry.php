<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class FutureFeatureRegistry
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $features = [
            ['CF05-FUT-001','Metric Certification Center','metric_governance','NEXT','elevated','smai_manage_catalog','Certified lifecycle for metrics.'],
            ['CF05-FUT-002','Metric Dependency Graph','metric_governance','NEXT','standard','smai_query_metrics','Graph upstream/downstream metric dependencies.'],
            ['CF05-FUT-003','Metric Impact Analyzer','metric_governance','NEXT','elevated','smai_manage_catalog','Dry-run downstream impact of metric/schema change.'],
            ['CF05-FUT-004','Metric Comparison Lab','metric_governance','NEXT','standard','smai_query_metrics','Compare versioned metric definitions and snapshots.'],
            ['CF05-FUT-005','Data Lineage Visual Explorer','lineage_catalog','NEXT','standard','smai_view_insights','Interactive source-to-report lineage evidence.'],
            ['CF05-FUT-006','Institutional Data Catalog','lineage_catalog','NEXT','standard','smai_view_insights','Governed dataset/metric/event catalog.'],
            ['CF05-FUT-007','Data Contract Control Center','lineage_catalog','NEXT','elevated','smai_manage_catalog','Contract ownership, compatibility and state control.'],
            ['CF05-FUT-008','Schema Evolution Simulator','lineage_catalog','NEXT','elevated','smai_manage_catalog','Read-only schema compatibility simulation.'],
            ['CF05-FUT-009','Historical Replay Lab','lineage_catalog','SCALE','elevated','smai_manage_backfills','Dry-run historical replay planning with checkpoints.'],
            ['CF05-FUT-010','Data Quality Command Center','quality_intelligence','NEXT','standard','smai_manage_quality','Unified freshness, completeness, duplicate and drift evidence.'],
            ['CF05-FUT-011','Automatic Anomaly Detection','quality_intelligence','SCALE','elevated','smai_run_future_intelligence','Detect aggregate anomalies without individual profiling.'],
            ['CF05-FUT-012','Root-Cause Analytics Assistant','quality_intelligence','SCALE','elevated','smai_run_future_intelligence','Evidence-ranked possible data/pipeline causes, advisory only.'],
            ['CF05-FUT-013','Pipeline Health Map','quality_intelligence','NEXT','standard','smai_view_insights','Pipeline checkpoints, jobs and quality state map.'],
            ['CF05-FUT-014','Data Freshness SLO Center','quality_intelligence','NEXT','standard','smai_manage_quality','Freshness objectives, breach evidence and alerts.'],
            ['CF05-FUT-015','Analytics Incident Center','quality_intelligence','NEXT','elevated','smai_manage_quality','Governed analytics incident lifecycle.'],
            ['CF05-FUT-016','Trusted Executive Scorecards','institutional_intelligence','NEXT','elevated','smai_view_insights','Approved aggregate KPI scorecards only.'],
            ['CF05-FUT-017','Domain Intelligence Packs','institutional_intelligence','NEXT','standard','smai_view_insights','Clinic/education/media/community/marketplace aggregate packs.'],
            ['CF05-FUT-018','Cross-Domain Intelligence','institutional_intelligence','SCALE','elevated','smai_run_future_intelligence','Privacy-safe aggregate cross-domain evidence.'],
            ['CF05-FUT-019','Trend Detection Engine','institutional_intelligence','NEXT','standard','smai_run_future_intelligence','Statistical aggregate trend classification.'],
            ['CF05-FUT-020','Forecasting Center','institutional_intelligence','SCALE','elevated','smai_run_future_intelligence','Bounded aggregate forecasting with uncertainty.'],
            ['CF05-FUT-021','Scenario Planning Lab','institutional_intelligence','SCALE','elevated','smai_manage_future_intelligence','Governed what-if scenario models.'],
            ['CF05-FUT-022','Capacity Planning Intelligence','institutional_intelligence','SCALE','elevated','smai_run_future_intelligence','Aggregate capacity projection and headroom.'],
            ['CF05-FUT-023','Cost Intelligence Center','institutional_intelligence','SCALE','elevated','smai_run_future_intelligence','Aggregate provider/infrastructure cost intelligence.'],
            ['CF05-FUT-024','Cost Anomaly Detection','institutional_intelligence','SCALE','elevated','smai_run_future_intelligence','Detect unusual aggregate cost movement.'],
            ['CF05-FUT-025','Privacy Budget Manager','privacy_research','NEXT','critical','smai_manage_access','Query privacy budget ledger and consumption controls.'],
            ['CF05-FUT-026','Differential Privacy Toolkit','privacy_research','EXPERIMENT','critical','smai_run_future_intelligence','Bounded aggregate noise preview; never raw-data release.'],
            ['CF05-FUT-027','Re-identification Risk Simulator','privacy_research','NEXT','critical','smai_manage_access','Pre-approval isolation and cohort risk simulation.'],
            ['CF05-FUT-028','Privacy-Safe Cohort Builder','privacy_research','NEXT','critical','smai_query_metrics','Allowlisted cohort definition with minimum-size guard.'],
            ['CF05-FUT-029','Institutional Research Workspace','privacy_research','NEXT','elevated','smai_manage_access','Metadata-only research workspaces over approved aggregates.'],
            ['CF05-FUT-030','Experiment Analysis Studio','privacy_research','NEXT','elevated','smai_manage_experiments','Experiment evidence, power, guardrails and decision record.'],
            ['CF05-FUT-031','Causal Analysis Lab','privacy_research','EXPERIMENT','critical','smai_run_future_intelligence','Constrained causal estimators with explicit assumptions.'],
            ['CF05-FUT-032','Benchmarking System','privacy_research','NEXT','standard','smai_query_metrics','Compare approved values to historical/approved benchmarks.'],
            ['CF05-FUT-033','Natural-Language Metric Query','ai_intelligence','EXPERIMENT','critical','smai_run_future_intelligence','Allowlisted intent parser to metric query plans.'],
            ['CF05-FUT-034','AI Analytics Copilot','ai_intelligence','EXPERIMENT','critical','smai_run_future_intelligence','Citation-bound aggregate explanation; no autonomous decision.'],
            ['CF05-FUT-035','AI Narrative Review Workflow','ai_intelligence','NEXT','critical','smai_manage_reports','AI-assisted draft, mandatory human review and publication.'],
            ['CF05-FUT-036','Proactive Intelligence Alerts','ai_intelligence','NEXT','elevated','smai_manage_future_intelligence','Governed threshold/anomaly/SLO alerts.'],
            ['CF05-FUT-037','Scheduled Intelligence Briefs','ai_intelligence','NEXT','elevated','smai_manage_reports','Scheduled aggregate institutional briefs.'],
            ['CF05-FUT-038','Analytics Transparency Center','ai_intelligence','NEXT','standard','smai_view_transparency','Public/staff disclosure of approved aggregate analytics usage.'],
            ['CF05-FUT-039','Synthetic Data & Simulation Lab','ai_intelligence','SCALE','elevated','smai_run_future_intelligence','Synthetic aggregate fixtures without real personal data.'],
            ['CF05-FUT-040','Analytics Disaster-Recovery Simulator','ai_intelligence','SCALE','critical','smai_restore','Read-only recovery/replay rehearsal against restore evidence.'],
        ];
        $out = [];
        foreach ($features as [$id,$title,$category,$phase,$risk,$capability,$purpose]) {
            $out[$id] = [
                'feature_id' => $id,
                'title' => $title,
                'category' => $category,
                'phase' => $phase,
                'risk' => $risk,
                'capability' => $capability,
                'purpose' => $purpose,
                'default_state' => 'disabled',
                'aggregate_only' => true,
                'human_governed' => true,
                'autonomous_decision' => false,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $featureId): ?array
    {
        return self::all()[strtoupper(trim($featureId))] ?? null;
    }

    /** @return array<int,string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }
}
