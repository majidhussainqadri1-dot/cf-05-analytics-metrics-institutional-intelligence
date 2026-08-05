#!/usr/bin/env python3
from __future__ import annotations
import pathlib, re, sys
root=pathlib.Path(__file__).resolve().parents[1]
patterns={
    'private key': re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
    'AWS access key': re.compile(r'AKIA[0-9A-Z]{16}'),
    'GitHub token': re.compile(r'gh[pousr]_[A-Za-z0-9_]{30,}'),
    'hard-coded secret': re.compile(r'(?i)(?:api[_-]?key|client[_-]?secret|password)\s*[=:]\s*[\'\"][^\'\"]{12,}[\'\"]'),
}
errors=[]
for p in root.rglob('*'):
    if not p.is_file() or any(part in {'.git','build','vendor'} for part in p.parts): continue
    try: text=p.read_text(errors='ignore')
    except Exception: continue
    for label,pattern in patterns.items():
        if pattern.search(text): errors.append(f'{label}: {p.relative_to(root)}')
if errors:
    print('\n'.join('ERROR: '+e for e in errors), file=sys.stderr); sys.exit(1)
print('Secret scan passed.')
