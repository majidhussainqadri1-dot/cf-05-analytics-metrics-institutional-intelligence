#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
d=(r/'src/Domain/DatasetCatalog.php').read_text(encoding='utf-8')
q=(r/'src/Domain/QualityService.php').read_text(encoding='utf-8')
p=(r/'src/Domain/PipelineService.php').read_text(encoding='utf-8')
checkpoint=(r/'src/Domain/CheckpointService.php').read_text(encoding='utf-8')
backfill=(r/'src/Domain/BackfillService.php').read_text(encoding='utf-8')
e=[]
if 'dataset_registered' not in d or 'logInOpenTransaction' not in d:e.append('dataset registration audit is not atomic')
if 'quality_rule_registered' not in q or "logInOpenTransaction('quality_rule_activated'" not in q:e.append('quality rule governance is not audit-atomic')
if "if (!$this->lineage->link" not in p:e.append('pipeline ignores lineage failure')
if "if (!(new CheckpointService($this->db))->advance" not in p:e.append('pipeline ignores checkpoint failure')
if "Dataset projection transaction could not start." not in p or "Dataset projection row could not be stored." not in p:e.append('pipeline projection is not fail-closed')
if "Dataset build row count could not be advanced." not in p:e.append('pipeline row-count persistence is unchecked')
if "isset($payload['_job_uuid'])" not in p:e.append('pipeline lineage is not bound to governed worker job identity')
if "Pipeline completion transaction could not start." not in p or "Event processing state could not be recorded." not in p:e.append('pipeline checkpoint/processed state is not atomic')
if "DateTimeImmutable::createFromFormat('!Y-m-d H:i:s'" not in checkpoint:e.append('checkpoint watermark parsing is not exact')
if backfill.count("if ($wpdb->query('START TRANSACTION') === false)") < 5:e.append('backfill mutation transactions are not consistently fail-closed')
if "Backfill correction projection failed." not in backfill:e.append('backfill correction write failure is ignored')
if "Existing active build could not be fenced." not in backfill:e.append('backfill activation can ignore active-build fencing failure')
if "Backfill numeric controls must be non-negative JSON integers." not in backfill or "Backfill hash-match control must be a JSON boolean." not in backfill:e.append('backfill definition controls are silently coerced')
if "!checkdate((int) $match[2]" not in backfill:e.append('backfill date parsing does not reject invalid calendar dates')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Pipeline/quality invariants check passed.')
