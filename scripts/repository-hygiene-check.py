#!/usr/bin/env python3
from pathlib import Path
import sys

root=Path(__file__).resolve().parents[1]
errors=[]
retired=[
    '.github/workflows/apply-future40.yml',
    '.github/workflows/apply-review40.yml',
    '.github/workflows/finalize-coding.yml',
    'scripts/apply-future40.py',
    'scripts/apply-review40.py',
    'scripts/finalize-coding.py',
]
for rel in retired:
    if (root/rel).exists(): errors.append('retired_mutation_tooling_present:'+rel)
marker=root/'.codex'
if marker.exists(): errors.append('one_shot_marker_directory_present:.codex')
for p in root.rglob('*'):
    if p.is_file() and (p.suffix in {'.pyc','.pyo'} or '__pycache__' in p.parts):
        errors.append('python_cache_artifact_present:'+p.relative_to(root).as_posix())
# Only the ordinary read/test/build CI is permitted to remain as a persistent
# workflow after source closure. Future review automation must be explicitly
# introduced, executed and self-removed inside its governed correction round.
workflow_dir=root/'.github/workflows'
allowed={'ci.yml'}
if workflow_dir.is_dir():
    for p in workflow_dir.iterdir():
        if p.is_file() and p.name not in allowed:
            errors.append('unexpected_persistent_workflow:'+p.name)
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Repository hygiene check passed: no retired mutation workflows/scripts or one-shot markers remain.')
