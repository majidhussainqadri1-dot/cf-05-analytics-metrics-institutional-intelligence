#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
d=(r/'src/Domain/DeletionService.php').read_text(encoding='utf-8')
p=(r/'src/Domain/ProviderService.php').read_text(encoding='utf-8')
x=(r/'src/Domain/RestoreService.php').read_text(encoding='utf-8')
t=(r/'src/Infrastructure/RetentionRunner.php').read_text(encoding='utf-8')
e=[]
if "audit->log('analytics_deletion_requested'" in d or "audit->log('analytics_deletion_completed'" in d:e.append('deletion governance audit is not atomic')
if 'provider_registered' not in p or "audit->log('provider_transition'" in p:e.append('provider governance audit is incomplete/non-atomic')
if "in_array($target,['approved','active'],true)?$actorUserId" in p:e.append('provider activation overwrites independent approver provenance')
if "['experiment_facts','subject_ref']" in x:e.append('restore deletion verification checks the wrong experiment-fact column')
if "audit->log('restore_point_recorded'" in x or "audit->log('warehouse_restore_verified'" in x:e.append('restore evidence audit is not atomic')
if 'analytics_retention_completed' not in t or 'mustQuery' not in t:e.append('retention can fail partially without durable evidence')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Deletion/retention/provider/restore invariants check passed.')
