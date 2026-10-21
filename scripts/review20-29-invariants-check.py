#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
checks={
 'event':(r/'src/Domain/EventIngestionService.php').read_text(encoding='utf-8'),
 'snapshot':(r/'src/Domain/SnapshotService.php').read_text(encoding='utf-8'),
 'export':(r/'src/Domain/ExportService.php').read_text(encoding='utf-8'),
 'backfill':(r/'src/Domain/BackfillService.php').read_text(encoding='utf-8'),
 'catalog':(r/'src/Domain/CatalogLifecycleService.php').read_text(encoding='utf-8'),
 'auth':(r/'src/Http/ServiceAuthenticator.php').read_text(encoding='utf-8'),
 'jobs':(r/'src/Infrastructure/JobQueue.php').read_text(encoding='utf-8'),
 'plugin':(r/'src/Plugin.php').read_text(encoding='utf-8'),
}
required=[
 ('event',"logInOpenTransaction(\n            'analytics_event_quarantined"),
 ('snapshot','PrivacyQueryPolicy::effectiveMinimum($definition,$dimensions'),
 ('snapshot',"logInOpenTransaction('metric_snapshot_published'"),
 ('export','PrivacyQueryPolicy::violations($metricDefinition, $dimensions)'),
 ('backfill',"logInOpenTransaction('backfill_activated'"),
 ('catalog',"logInOpenTransaction('catalog_lifecycle_transition'"),
 ('auth','. $service . "\\n"'),
 ('jobs','lease_attempts_exhausted'),
 ('plugin','if (!$this->upgradeIfNeeded())'),
]
def compact(value: str) -> str:
    return ''.join(value.split())

missing=[f'{name}: {needle}' for name,needle in required if compact(needle) not in compact(checks[name])]
if missing:
 print('Review 20-29 invariant regression:\n'+'\n'.join(missing),file=sys.stderr);sys.exit(1)
print('Review 20-29 permanent invariants check passed.')
