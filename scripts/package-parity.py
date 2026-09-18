#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,pathlib,sys,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
slug='sabri-analytics-institutional-intelligence'; version='1.0.0-rc.8'
archive=root/'build'/'dist'/f'CF-05-{slug}-{version}.zip'
manifest_path=root/'build'/'dist'/f'CF-05-{version}-package-manifest.json'
if not archive.is_file() or not manifest_path.is_file():
 print('release artifact missing',file=sys.stderr);sys.exit(1)
files=[root/'sabri-analytics-institutional-intelligence.php',root/'readme.txt',root/'README.md',root/'CHANGELOG.md',root/'MANIFEST.json',root/'SECURITY.md',root/'uninstall.php']
for name in ['assets','contracts','src']:
 files.extend(p for p in (root/name).rglob('*') if p.is_file())
files=sorted(set(files),key=lambda p:p.as_posix())
expected={f'{slug}/{p.relative_to(root).as_posix()}':p.read_bytes() for p in files}
with zipfile.ZipFile(archive) as z:
 actual={name:z.read(name) for name in z.namelist() if not name.endswith('/')}
if set(actual)!=set(expected):
 print('package file-set mismatch',file=sys.stderr)
 print('missing',sorted(set(expected)-set(actual)),file=sys.stderr)
 print('extra',sorted(set(actual)-set(expected)),file=sys.stderr)
 sys.exit(1)
for name,data in expected.items():
 if actual[name] != data:
  print(f'package byte mismatch: {name}',file=sys.stderr);sys.exit(1)
manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
sha=hashlib.sha256(archive.read_bytes()).hexdigest()
if manifest.get('sha256')!=sha or manifest.get('file_count')!=len(expected) or manifest.get('review_rounds_completed')!=40:
 print('package manifest mismatch',file=sys.stderr);sys.exit(1)
print(f'Package/source parity passed: {len(expected)} files, {sha}.')
