#!/usr/bin/env python3
"""SR470-509: 40 fresh requirement/ownership source probes.

These are forty independent *source-integrity* checks. They do NOT prove
WordPress database readiness, native companion integrations, staging or live.
Forty additional executable negative Future-40 privacy tests run separately.
"""
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
controls=json.loads("[[\"Event schema registry\",\"src/Domain/EventSchemaRegistry.php\",\"event_name\",\"version\"],[\"Minimum event envelope\",\"src/Domain/EventIngestionService.php\",\"event_id\",\"source_environment\"],[\"Purpose/consent gate\",\"src/Domain/EventIngestionService.php\",\"consent\",\"purpose\"],[\"Field redaction/tokenization\",\"src/Domain/PrivacyGateway.php\",\"pseudonym\",\"unknown_field\"],[\"Dedupe/order/late events\",\"src/Domain/EventIngestionService.php\",\"sequence\",\"source_sequence_key\"],[\"Quarantine and feedback\",\"src/Domain/EventIngestionService.php\",\"quarantine\",\"reason\"],[\"Canonical derivative modeling\",\"src/Domain/DatasetCatalog.php\",\"dataset\",\"source\"],[\"Slowly changing definitions\",\"src/Contracts/DatasetDefinitionValidator.php\",\"version\",\"histor\"],[\"Data lineage\",\"src/Domain/LineageService.php\",\"lineage\",\"owner\"],[\"Backfill/rebuild\",\"src/Domain/BackfillService.php\",\"backfill\",\"rollback\"],[\"Data quality rules\",\"src/Domain/QualityService.php\",\"quality\",\"freshness\"],[\"Region/provider control\",\"src/Domain/ProviderService.php\",\"provider\",\"region\"],[\"Metric definition\",\"src/Contracts/MetricDefinitionValidator.php\",\"numerator\",\"denominator\"],[\"Metric lifecycle\",\"src/Domain/CatalogLifecycleService.php\",\"transition\",\"metric\"],[\"Dimension allowlist\",\"src/Domain/MetricQueryService.php\",\"allowedDimensions\",\"dimension\"],[\"Freshness and confidence\",\"src/Domain/SnapshotDisclosurePolicy.php\",\"freshness\",\"caveat\"],[\"Attribution and funnels\",\"src/Domain/FunnelAttributionCalculator.php\",\"conversion\",\"first_touch\"],[\"Financial/clinical metric limits\",\"src/Infrastructure/SensitiveValueDetector.php\",\"clinical\",\"payment\"],[\"Role/project-scoped dashboards\",\"src/Domain/DashboardService.php\",\"authorizesViewer\",\"audience\"],[\"Metric presentation\",\"src/Presentation/InsightsShortcode.php\",\"shortcode\",\"metric\"],[\"Drilldown safety\",\"src/Domain/QueryPrivacyGuard.php\",\"differencing\",\"budget\"],[\"Scheduled reports\",\"src/Domain/ReportService.php\",\"scheduleDue\",\"recipientScoped\"],[\"Secure exports\",\"src/Domain/ExportService.php\",\"CryptoBox\",\"CSV\"],[\"Narrative insights\",\"src/Domain/NarrativeService.php\",\"narrative\",\"evidence\"],[\"Experiment registry\",\"src/Domain/ExperimentService.php\",\"experiment\",\"hypothesis\"],[\"Assignment separation\",\"src/Domain/ExperimentService.php\",\"assignment\",\"owner\"],[\"Guardrail monitoring\",\"src/Domain/ExperimentService.php\",\"guardrail\",\"stop\"],[\"Statistical integrity\",\"src/Domain/Statistics.php\",\"proportionInterval\",\"twoSidedNormalPValue\"],[\"Decision record\",\"src/Domain/ExperimentService.php\",\"decision\",\"audit\"],[\"Retention tiers\",\"src/Infrastructure/RetentionRunner.php\",\"retention\",\"expire\"],[\"Deletion/anonymization propagation\",\"src/Domain/DeletionService.php\",\"deletion\",\"revoke\"],[\"Access projects and expiry\",\"src/Domain/AccessProjectService.php\",\"authorizesViewer\",\"expires_at\"],[\"Query and export audit\",\"src/Infrastructure/AuditLogger.php\",\"audit\",\"logInOpenTransaction\"],[\"Provider/warehouse exit\",\"src/Domain/ProviderService.php\",\"provider\",\"exit\"],[\"Restore and reproducibility\",\"src/Domain/RestoreService.php\",\"restore\",\"catalog_hash\"],[\"Central owner/derivative boundary\",\"MANIFEST.json\",\"non_goals\",\"privacy\"],[\"AI, communication and marketplace event separation\",\"src/Infrastructure/SensitiveValueDetector.php\",\"message\",\"payment\"],[\"Clinical/support/localization ownership boundaries\",\"src/Infrastructure/IntegrationRegistry.php\",\"owner\",\"contract\"],[\"Future-40 separate approval/activation\",\"src/Infrastructure/FutureActivationService.php\",\"activation\",\"approval\"],[\"Schema/migration/staging/live parity\",\"MANIFEST.json\",\"live_deployed\",\"schema_version\"]]")

errors=[]
for index, (subject, path, witness_a, witness_b) in enumerate(controls,470):
    p=root/path
    if not p.is_file():
        errors.append(f"SR{index}: {subject}: missing exact source {path}")
        continue
    source=p.read_text(encoding='utf-8')
    if path.endswith('.php'):
        # A functioning PHP implementation is more than a comment or placeholder.
        if not source.startswith('<?php') or 'declare(strict_types=1);' not in source:
            errors.append(f"SR{index}: {subject}: missing PHP strict module declaration")
        if 'class ' not in source or 'function ' not in source:
            errors.append(f"SR{index}: {subject}: missing executable class/functions")
    if not all(s.casefold() in source.casefold() for s in (witness_a, witness_b)):
        errors.append(f"SR{index}: {subject}: missing requirement-specific two-witness contract")
    print(f"{'FAIL' if any(e.startswith(f'SR{index}:') for e in errors) else 'PASS'} SR-{index}: {subject}")
manifest=json.loads((root/'MANIFEST.json').read_text(encoding='utf-8'))
if not (manifest.get('source_completion',{}).get('staging_accepted') is False
        and manifest.get('source_completion',{}).get('live_deployed') is False
        and manifest.get('source_completion',{}).get('operational') is False):
    errors.append('Live-truth invariant: manifest improperly claims staging/live/operational')
if manifest.get('runtime_default') != 'foundation_disabled':
    errors.append('Runtime source release default is not fail-closed')
job=(root/'src/Infrastructure/JobQueue.php').read_text(encoding='utf-8')
for evidence in ('job_type,payload_json,max_attempts','storedRetryText','normalizedMaxAttempts',
                 'smai_job_idempotency_payload_conflict'):
    if evidence not in job: errors.append('Immutable job retry budget regression: '+evidence)
qa=(root/'scripts/qa.sh').read_text(encoding='utf-8')
for test in ('tests/sr470-509-job-immutability.php',
             'tests/sr470-509-future40-privacy.php',
             'scripts/sr470-509-source-integrity-check.py'):
    if test not in qa: errors.append('Mandatory CI gate missing '+test)
if errors:raise SystemExit('SR470–509 source integrity FAIL:\n'+'\n'.join(errors))
print('SR470–509: 40/40 independently re-inspected source controls; additional retry/CI/truth gates pass.')
print('Source assertions only: producer/consumer staging contracts and actual deployed WordPress/DB remain unverified.')
