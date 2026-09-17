#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
d=(r/'src/Domain/DatasetCatalog.php').read_text(encoding='utf-8')
q=(r/'src/Domain/QualityService.php').read_text(encoding='utf-8')
p=(r/'src/Domain/PipelineService.php').read_text(encoding='utf-8')
e=[]
if 'dataset_registered' not in d or 'logInOpenTransaction' not in d:e.append('dataset registration audit is not atomic')
if 'quality_rule_registered' not in q or "logInOpenTransaction('quality_rule_activated'" not in q:e.append('quality rule governance is not audit-atomic')
if "if (!$this->lineage->link" not in p:e.append('pipeline ignores lineage failure')
if "if (!(new CheckpointService($this->db))->advance" not in p:e.append('pipeline ignores checkpoint failure')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Pipeline/quality invariants check passed.')
