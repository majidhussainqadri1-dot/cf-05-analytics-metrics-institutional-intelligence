#!/usr/bin/env python3
"""SR350-389: source-location drift gate; NOT a runtime integration certification."""
import json
from pathlib import Path
root = Path(__file__).resolve().parent.parent
checks = json.loads("[[\"Event schema registry\",\"src/Domain/EventSchemaRegistry.php\",\"schema\"],[\"Minimum event envelope\",\"src/Domain/EventIngestionService.php\",\"event_id\"],[\"Purpose/consent gate\",\"src/Domain/EventIngestionService.php\",\"consent\"],[\"Field redaction/tokenization\",\"src/Domain/PrivacyGateway.php\",\"pseudonymize\"],[\"Dedupe/order/late events\",\"src/Domain/EventIngestionService.php\",\"sequence\"],[\"Quarantine and feedback\",\"src/Domain/EventIngestionService.php\",\"quarantine\"],[\"Canonical derivative modeling\",\"src/Domain/DatasetCatalog.php\",\"dataset\"],[\"Slowly changing definitions\",\"src/Contracts/DatasetDefinitionValidator.php\",\"version\"],[\"Data lineage\",\"src/Domain/LineageService.php\",\"lineage\"],[\"Backfill/rebuild\",\"src/Domain/BackfillService.php\",\"backfill\"],[\"Data quality rules\",\"src/Domain/QualityService.php\",\"quality\"],[\"Region/provider control\",\"src/Domain/ProviderService.php\",\"region\"],[\"Metric definition\",\"src/Contracts/MetricDefinitionValidator.php\",\"numerator\"],[\"Metric lifecycle\",\"src/Domain/CatalogLifecycleService.php\",\"state\"],[\"Dimension allowlist\",\"src/Domain/MetricQueryService.php\",\"allowedDimensions\"],[\"Freshness and confidence\",\"src/Domain/SnapshotDisclosurePolicy.php\",\"freshness\"],[\"Attribution and funnels\",\"src/Domain/FunnelAttributionCalculator.php\",\"last_touch\"],[\"Financial/clinical metric limits\",\"src/Infrastructure/SensitiveValueDetector.php\",\"clinical\"],[\"Role/project-scoped dashboards\",\"src/Domain/DashboardService.php\",\"audienceAllows\"],[\"Metric presentation\",\"src/Presentation/InsightsShortcode.php\",\"shortcode\"],[\"Drilldown safety\",\"src/Domain/QueryPrivacyGuard.php\",\"differencing\"],[\"Scheduled reports\",\"src/Domain/ReportService.php\",\"scheduleDue\"],[\"Secure exports\",\"src/Domain/ExportService.php\",\"CryptoBox\"],[\"Narrative insights\",\"src/Domain/NarrativeService.php\",\"narrative\"],[\"Experiment registry\",\"src/Domain/ExperimentService.php\",\"experiment\"],[\"Assignment separation\",\"src/Domain/ExperimentService.php\",\"assignment\"],[\"Guardrail monitoring\",\"src/Domain/ExperimentService.php\",\"guardrail\"],[\"Statistical integrity\",\"src/Domain/Statistics.php\",\"proportionInterval\"],[\"Decision record\",\"src/Domain/ExperimentService.php\",\"decision\"],[\"Retention tiers\",\"src/Infrastructure/RetentionRunner.php\",\"retention\"],[\"Deletion/anonymization propagation\",\"src/Domain/DeletionService.php\",\"deletion\"],[\"Access projects and expiry\",\"src/Domain/AccessProjectService.php\",\"expires_at\"],[\"Query and export audit\",\"src/Infrastructure/AuditLogger.php\",\"audit\"],[\"Provider/warehouse exit\",\"src/Domain/ProviderService.php\",\"exit\"],[\"Restore and reproducibility\",\"src/Domain/RestoreService.php\",\"restore\"],[\"Central canonical ownership\",\"MANIFEST.json\",\"non_goals\"],[\"File 16/17/18 and CF-01/02 sensitive exclusion\",\"src/Infrastructure/SensitiveValueDetector.php\",\"message\"],[\"Public traffic and ranking separation\",\"src/Infrastructure/IntegrationRegistry.php\",\"owner\"],[\"Future40 separate approvals\",\"src/Infrastructure/FutureActivationService.php\",\"activation\"],[\"Schema, migrations, staging/live parity\",\"MANIFEST.json\",\"live_deployed\"]]")
if len(checks)!=40: raise SystemExit("Expected forty distinct reviewed subjects")
errors=[]
for index,(subject,path,token) in enumerate(checks,350):
    source=root/path
    if not source.is_file() or token.casefold() not in source.read_text(encoding="utf-8").casefold():
        errors.append(f"SR{index}: {subject}: {path}")
if errors:
    raise SystemExit("Missing source evidence:\n"+"\n".join(errors))
print("SR350-389 source target presence: 40/40. Runtime/staging/live parity remains independent.")

# Additional behavioral-boundary implementation checks for this cycle.
boundary_checks = [
    ('per-viewer bounded grants', 'src/Domain/AccessProjectService.php', 'function authorizesViewer('),
    ('dashboard no owner identity proxy', 'src/Domain/DashboardService.php', '$viewerAccess->authorizesViewer('),
    ('delivery recipient independent grant', 'src/Domain/ReportService.php', "$recipientAccess->authorizesViewer("),
    ('report creation checks recipient scope', 'src/Domain/ReportService.php', "'smai_report_recipient_scope_denied'"),
    ('report schedule rechecks recipient', 'src/Domain/ReportService.php', 'if (!$recipientScoped'),
]
for subject, path, token in boundary_checks:
    s = root / path
    if not s.is_file() or token not in s.read_text(encoding='utf-8'):
        raise SystemExit('Delegated-access guard missing: '+subject)
print("SR350-389: five delegated-access implementation guards present; real behavior also separately tested.")
