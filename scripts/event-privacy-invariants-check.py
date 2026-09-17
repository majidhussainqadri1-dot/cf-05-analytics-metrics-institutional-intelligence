#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
pg=(root/'src/Domain/PrivacyGateway.php').read_text(encoding='utf-8')
ing=(root/'src/Domain/EventIngestionService.php').read_text(encoding='utf-8')
reg=(root/'src/Domain/EventSchemaRegistry.php').read_text(encoding='utf-8')
errors=[]
for token,msg in [
    ("unknown_field_",'unknown event properties are not fail-closed'),
    ("missing_required_",'required event properties are not enforced'),
    ("$minorAggregateOnly ? null",'minor aggregate-only path retains direct pseudonymous refs')]:
    if token not in pg: errors.append(msg)
if "logInOpenTransaction" not in ing or "accepted_audit_degraded" in ing: errors.append('event acceptance is not audit-atomic')
if "logInOpenTransaction('event_schema_registered'" not in reg: errors.append('event schema registration is not audit-atomic')
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Event/privacy invariants check passed.')
