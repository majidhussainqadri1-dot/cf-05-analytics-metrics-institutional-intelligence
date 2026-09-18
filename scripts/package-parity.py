#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,pathlib,re,sys,zipfile

root=pathlib.Path(__file__).resolve().parents[1]
slug='sabri-analytics-institutional-intelligence'
plugin_path=root/'sabri-analytics-institutional-intelligence.php'
plugin=plugin_path.read_text(encoding='utf-8')
match=re.search(r"define\('SMAI_VERSION',\s*'([^']+)'\);",plugin)
if not match:
    print('release version constant missing',file=sys.stderr);sys.exit(1)
version=match.group(1)

archive=root/'build'/'dist'/f'CF-05-{slug}-{version}.zip'
manifest_path=root/'build'/'dist'/f'CF-05-{version}-package-manifest.json'
if not archive.is_file() or not manifest_path.is_file():
    print('release artifact missing',file=sys.stderr);sys.exit(1)

files=[plugin_path,root/'readme.txt',root/'README.md',root/'CHANGELOG.md',root/'MANIFEST.json',root/'SECURITY.md',root/'uninstall.php']
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
review_docs=list((root/'docs').glob('SEQUENTIAL-REVIEW-ROUND-*.md'))
review_rounds_completed=max([int(p.stem.rsplit('-',1)[-1]) for p in review_docs if p.stem.rsplit('-',1)[-1].isdigit()] or [0])
if manifest.get('version')!=version or manifest.get('sha256')!=sha or manifest.get('file_count')!=len(expected) or manifest.get('review_rounds_completed')!=review_rounds_completed:
    print('package manifest mismatch',file=sys.stderr);sys.exit(1)
print(f'Package/source parity passed: {len(expected)} files, {sha}.')
