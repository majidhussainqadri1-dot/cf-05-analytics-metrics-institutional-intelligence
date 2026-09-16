#!/usr/bin/env python3
from __future__ import annotations
import pathlib,re,sys
root=pathlib.Path(__file__).resolve().parents[1]
skip={'.git','build'}
patterns={
 'private-key':re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
 'github-token':re.compile(r'gh[pousr]_[A-Za-z0-9]{30,}'),
 'aws-key':re.compile(r'AKIA[0-9A-Z]{16}'),
 'jwt':re.compile(r'\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b'),
}
fail=[]
for path in root.rglob('*'):
 if not path.is_file() or any(part in skip for part in path.parts): continue
 if path.suffix.lower() not in {'.php','.json','.md','.txt','.yml','.yaml','.py','.sh'}: continue
 text=path.read_text(encoding='utf-8',errors='ignore')
 for label,pattern in patterns.items():
  if pattern.search(text): fail.append(f'{path.relative_to(root)}:{label}')
if fail:
 print('\n'.join(fail),file=sys.stderr);sys.exit(1)
print('Secret scan passed.')
