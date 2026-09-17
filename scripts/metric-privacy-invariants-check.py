#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
q=(r/'src/Domain/QueryPrivacyGuard.php').read_text(encoding='utf-8')
m=(r/'src/Domain/MetricQueryService.php').read_text(encoding='utf-8')
c=(r/'src/Domain/MetricCatalog.php').read_text(encoding='utf-8')
errors=[]
if "hash('sha256', Json::canonical($value))" in q or "dimensions_fingerprint' => hash('sha256'" in q: errors.append('query privacy fingerprints are unkeyed')
if 'SELECT GET_LOCK' not in q or 'SELECT RELEASE_LOCK' not in q: errors.append('privacy budget accounting is not serialized')
if "$stored === false" not in q or 'smai_privacy_evidence_unavailable' not in q: errors.append('privacy evidence persistence can fail open')
if ": hash('sha256', $dimensionsJson)" in m: errors.append('metric audit fingerprint has unkeyed fallback')
if "logInOpenTransaction" not in c: errors.append('metric registration audit is not transactional')
if errors: print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Metric privacy invariants check passed.')
