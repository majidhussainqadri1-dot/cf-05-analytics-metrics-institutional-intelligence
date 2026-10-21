#!/usr/bin/env python3
"""40 EXACT CF-05 plan-to-source anchors; NOT end-to-end runtime acceptance."""
import json, pathlib, sys
root=pathlib.Path(__file__).resolve().parents[1]
items=[
  {
    "id": "CF05-FR-001",
    "title": "Event schema registry",
    "path": "src/Domain/EventSchemaRegistry.php",
    "anchor": "class EventSchemaRegistry"
  },
  {
    "id": "CF05-FR-002",
    "title": "Minimum event envelope",
    "path": "src/Domain/EventIngestionService.php",
    "anchor": "ENVELOPE_KEYS"
  },
  {
    "id": "CF05-FR-003",
    "title": "Purpose / consent gate",
    "path": "src/Domain/PrivacyGateway.php",
    "anchor": "requires_consent"
  },
  {
    "id": "CF05-FR-004",
    "title": "Field redaction / tokenization",
    "path": "src/Domain/PrivacyGateway.php",
    "anchor": "SensitiveValueDetector"
  },
  {
    "id": "CF05-FR-005",
    "title": "Dedupe, order and late events",
    "path": "src/Domain/EventIngestionService.php",
    "anchor": "source_sequence"
  },
  {
    "id": "CF05-FR-006",
    "title": "Quarantine and owner feedback",
    "path": "src/Domain/EventIngestionService.php",
    "anchor": "function quarantine"
  },
  {
    "id": "CF05-FR-007",
    "title": "Derivative model / canonical IDs",
    "path": "src/Domain/DatasetCatalog.php",
    "anchor": "class DatasetCatalog"
  },
  {
    "id": "CF05-FR-008",
    "title": "Slowly changing historical definitions",
    "path": "src/Domain/DatasetCatalog.php",
    "anchor": "class DatasetCatalog"
  },
  {
    "id": "CF05-FR-009",
    "title": "Data lineage",
    "path": "src/Domain/LineageService.php",
    "anchor": "class LineageService"
  },
  {
    "id": "CF05-FR-010",
    "title": "Backfill and shadow build",
    "path": "src/Domain/BackfillService.php",
    "anchor": "class BackfillService"
  },
  {
    "id": "CF05-FR-011",
    "title": "Data quality rules",
    "path": "src/Domain/QualityService.php",
    "anchor": "class QualityService"
  },
  {
    "id": "CF05-FR-012",
    "title": "Region and provider control",
    "path": "src/Domain/ProviderService.php",
    "anchor": "class ProviderService"
  },
  {
    "id": "CF05-FR-013",
    "title": "Semantic metric definition",
    "path": "src/Contracts/MetricDefinitionValidator.php",
    "anchor": "class MetricDefinitionValidator"
  },
  {
    "id": "CF05-FR-014",
    "title": "Metric lifecycle and versioning",
    "path": "src/Domain/MetricCatalog.php",
    "anchor": "class MetricCatalog"
  },
  {
    "id": "CF05-FR-015",
    "title": "Dimension allowlist",
    "path": "src/Domain/PrivacyQueryPolicy.php",
    "anchor": "class PrivacyQueryPolicy"
  },
  {
    "id": "CF05-FR-016",
    "title": "Freshness and confidence",
    "path": "src/Domain/MetricQueryService.php",
    "anchor": "freshnessSeconds"
  },
  {
    "id": "CF05-FR-017",
    "title": "Attribution and funnels",
    "path": "src/Domain/SnapshotService.php",
    "anchor": "class SnapshotService"
  },
  {
    "id": "CF05-FR-018",
    "title": "Finance and clinical aggregate limits",
    "path": "src/Domain/PrivacyQueryPolicy.php",
    "anchor": "class PrivacyQueryPolicy"
  },
  {
    "id": "CF05-FR-019",
    "title": "Project-scoped dashboards",
    "path": "src/Domain/DashboardService.php",
    "anchor": "class DashboardService"
  },
  {
    "id": "CF05-FR-020",
    "title": "Metric presentation",
    "path": "src/Domain/MetricQueryService.php",
    "anchor": "quality_status"
  },
  {
    "id": "CF05-FR-021",
    "title": "Drill-down, tiny cohort and differencing safety",
    "path": "src/Domain/QueryPrivacyGuard.php",
    "anchor": "differencing"
  },
  {
    "id": "CF05-FR-022",
    "title": "Scheduled reports",
    "path": "src/Domain/ReportService.php",
    "anchor": "class ReportService"
  },
  {
    "id": "CF05-FR-023",
    "title": "Secure exports",
    "path": "src/Domain/ExportService.php",
    "anchor": "CsvSafe"
  },
  {
    "id": "CF05-FR-024",
    "title": "Evidence-linked narrative insights",
    "path": "src/Domain/NarrativeService.php",
    "anchor": "class NarrativeService"
  },
  {
    "id": "CF05-FR-025",
    "title": "Experiment registry",
    "path": "src/Domain/ExperimentService.php",
    "anchor": "class ExperimentService"
  },
  {
    "id": "CF05-FR-026",
    "title": "Assignment owner separation",
    "path": "src/Domain/ExperimentService.php",
    "anchor": "assignment"
  },
  {
    "id": "CF05-FR-027",
    "title": "Experiment guardrail monitoring",
    "path": "src/Domain/ExperimentService.php",
    "anchor": "guardrail"
  },
  {
    "id": "CF05-FR-028",
    "title": "Statistical integrity",
    "path": "src/Domain/Statistics.php",
    "anchor": "class Statistics"
  },
  {
    "id": "CF05-FR-029",
    "title": "Human decision record",
    "path": "src/Domain/ExperimentService.php",
    "anchor": "decision"
  },
  {
    "id": "CF05-FR-030",
    "title": "Retention tiers",
    "path": "src/Infrastructure/RetentionRunner.php",
    "anchor": "class RetentionRunner"
  },
  {
    "id": "CF05-FR-031",
    "title": "Deletion propagation",
    "path": "src/Domain/DeletionService.php",
    "anchor": "class DeletionService"
  },
  {
    "id": "CF05-FR-032",
    "title": "Access-project expiry",
    "path": "src/Domain/AccessProjectService.php",
    "anchor": "class AccessProjectService"
  },
  {
    "id": "CF05-FR-033",
    "title": "Query and export audit",
    "path": "src/Domain/MetricQueryService.php",
    "anchor": "AuditLogger"
  },
  {
    "id": "CF05-FR-034",
    "title": "Provider exit",
    "path": "src/Domain/ProviderService.php",
    "anchor": "class ProviderService"
  },
  {
    "id": "CF05-FR-035",
    "title": "Restore and reproducibility",
    "path": "src/Domain/RestoreService.php",
    "anchor": "class RestoreService"
  }
]
failed=[]
for idx,item in enumerate(items,1):
 p=root/item["path"]
 ok=p.is_file() and item["anchor"] in p.read_text(encoding="utf-8")
 print(("PASS" if ok else "FAIL")+f" {idx:02d}/40 {item['id']} {item['title']} -> {item['path']}")
 if not ok: failed.append(item["id"])
checks=[
 ("CEN-OWN","derivative-only owner never becomes domain truth", "MANIFEST.json", '"identity, roles or domain entity source of truth"'),
 ("CEN-ACT","foundation must be default-disabled", "src/Infrastructure/RuntimeGate.php", "FOUNDATION_DISABLED"),
 ("CEN-FUT40","future40 full ID range is declared", "src/Domain/FutureFeatureRegistry.php", "CF05-FUT-040"),
 ("CEN-ADVISORY","future40 is advisory-only", "src/Domain/Future40Engine.php", "advisory_only"),
 ("CEN-TRUTH","manifest does not claim live/staging completion", "MANIFEST.json", '"live_deployed": false'),
]
for idx,(code,label,path,anchor) in enumerate(checks,36):
 p=root/path;ok=p.is_file() and anchor in p.read_text(encoding="utf-8")
 if code=="CEN-TRUTH" and ok:
  m=json.loads(p.read_text(encoding="utf-8"))
  ok=m.get("source_completion",{}).get("staging_accepted") is False and m["source_completion"].get("live_deployed") is False
 print(("PASS" if ok else "FAIL")+f" {idx:02d}/40 {code} {label}")
 if not ok: failed.append(code)
assert len(items)==35 and len(checks)==5
print(f"CF-05 FRESH plan source anchor review: {40-len(failed)}/40 pass, {len(failed)} missing/misaligned; no staging/live inference")
sys.exit(1 if failed else 0)
