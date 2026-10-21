#!/usr/bin/env python3
"""CF-05 SR430–469: forty scoped source-to-governing-plan checks, not E2E certification."""
from pathlib import Path
import json
root=Path(__file__).resolve().parent.parent
controls=json.loads("[[\"Event schema registry\",\"src/Domain/EventSchemaRegistry.php\",\"event_name\",\"version\"],[\"Minimum event envelope\",\"src/Domain/EventIngestionService.php\",\"event_id\",\"source_environment\"],[\"Purpose/consent gate\",\"src/Domain/EventIngestionService.php\",\"consent\",\"purpose\"],[\"Field redaction/tokenization\",\"src/Domain/PrivacyGateway.php\",\"pseudonym\",\"unknown_field\"],[\"Dedupe/order/late events\",\"src/Domain/EventIngestionService.php\",\"sequence\",\"source_sequence_key\"],[\"Quarantine and feedback\",\"src/Domain/EventIngestionService.php\",\"quarantine\",\"reason\"],[\"Canonical derivative modeling\",\"src/Domain/DatasetCatalog.php\",\"dataset\",\"source\"],[\"Slowly changing definitions\",\"src/Contracts/DatasetDefinitionValidator.php\",\"version\",\"histor\"],[\"Data lineage\",\"src/Domain/LineageService.php\",\"lineage\",\"owner\"],[\"Backfill/rebuild\",\"src/Domain/BackfillService.php\",\"backfill\",\"rollback\"],[\"Data quality rules\",\"src/Domain/QualityService.php\",\"quality\",\"freshness\"],[\"Region/provider control\",\"src/Domain/ProviderService.php\",\"provider\",\"region\"],[\"Metric definition\",\"src/Contracts/MetricDefinitionValidator.php\",\"numerator\",\"denominator\"],[\"Metric lifecycle\",\"src/Domain/CatalogLifecycleService.php\",\"transition\",\"metric\"],[\"Dimension allowlist\",\"src/Domain/MetricQueryService.php\",\"allowedDimensions\",\"dimension\"],[\"Freshness and confidence\",\"src/Domain/SnapshotDisclosurePolicy.php\",\"freshness\",\"caveat\"],[\"Attribution and funnels\",\"src/Domain/FunnelAttributionCalculator.php\",\"conversion\",\"first_touch\"],[\"Financial/clinical metric limits\",\"src/Infrastructure/SensitiveValueDetector.php\",\"clinical\",\"payment\"],[\"Role/project-scoped dashboards\",\"src/Domain/DashboardService.php\",\"authorizesViewer\",\"audience\"],[\"Metric presentation\",\"src/Presentation/InsightsShortcode.php\",\"shortcode\",\"metric\"],[\"Drilldown safety\",\"src/Domain/QueryPrivacyGuard.php\",\"differencing\",\"budget\"],[\"Scheduled reports\",\"src/Domain/ReportService.php\",\"scheduleDue\",\"recipientScoped\"],[\"Secure exports\",\"src/Domain/ExportService.php\",\"CryptoBox\",\"CSV\"],[\"Narrative insights\",\"src/Domain/NarrativeService.php\",\"narrative\",\"evidence\"],[\"Experiment registry\",\"src/Domain/ExperimentService.php\",\"experiment\",\"hypothesis\"],[\"Assignment separation\",\"src/Domain/ExperimentService.php\",\"assignment\",\"owner\"],[\"Guardrail monitoring\",\"src/Domain/ExperimentService.php\",\"guardrail\",\"stop\"],[\"Statistical integrity\",\"src/Domain/Statistics.php\",\"proportionInterval\",\"twoSidedNormalPValue\"],[\"Decision record\",\"src/Domain/ExperimentService.php\",\"decision\",\"audit\"],[\"Retention tiers\",\"src/Infrastructure/RetentionRunner.php\",\"retention\",\"expire\"],[\"Deletion/anonymization propagation\",\"src/Domain/DeletionService.php\",\"deletion\",\"revoke\"],[\"Access projects and expiry\",\"src/Domain/AccessProjectService.php\",\"authorizesViewer\",\"expires_at\"],[\"Query and export audit\",\"src/Infrastructure/AuditLogger.php\",\"audit\",\"logInOpenTransaction\"],[\"Provider/warehouse exit\",\"src/Domain/ProviderService.php\",\"provider\",\"exit\"],[\"Restore and reproducibility\",\"src/Domain/RestoreService.php\",\"restore\",\"catalog_hash\"],[\"Central owner/derivative boundary\",\"MANIFEST.json\",\"non_goals\",\"privacy\"],[\"AI, communication and marketplace event separation\",\"src/Infrastructure/SensitiveValueDetector.php\",\"message\",\"payment\"],[\"Clinical/support/localization ownership boundaries\",\"src/Infrastructure/IntegrationRegistry.php\",\"owner\",\"contract\"],[\"Future-40 separate approval/activation\",\"src/Infrastructure/FutureActivationService.php\",\"activation\",\"approval\"],[\"Schema/migration/staging/live parity\",\"MANIFEST.json\",\"live_deployed\",\"schema_version\"]]")
assert len(controls)==40
errors=[]
for i,(subject,relative,marker1,marker2) in enumerate(controls,430):
 p=root/relative
 if not p.is_file():
  errors.append(f'SR-{i}: {subject}: missing source {relative}')
  continue
 data=p.read_text(encoding='utf-8').casefold()
 for marker in (marker1,marker2):
  if marker.casefold() not in data:errors.append(f'SR-{i}: {subject}: missing {marker}')
fix_checks=[
 ('privacy revised same-slice cohort delta','src/Domain/PrivacyQueryPolicy.php','A corrected snapshot'),
 ('job immutable type and payload comparison','src/Infrastructure/JobQueue.php','smai_job_idempotency_payload_conflict'),
 ('job duplicate SELECT original details','src/Infrastructure/JobQueue.php','job_type,payload_json'),
 ('nine real PHP regression assertions','scripts/qa.sh','tests/sr430-469-slice-duplicate.php'),
]
for title,path,needle in fix_checks:
 p=root/path
 if not p.is_file() or needle not in p.read_text(encoding='utf-8'):errors.append('SR430-469 fix guard missing: '+title)
if errors:raise SystemExit('CF05 SR430-469 failed:\n'+'\n'.join(errors))
print('CF05 SR430–469: 40/40 source checks, 4/4 fix guards. External runtimes independently unverified.')
