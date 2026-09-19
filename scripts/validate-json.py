#!/usr/bin/env python3
from __future__ import annotations
import json, pathlib, sys
root=pathlib.Path(__file__).resolve().parents[1]
files=[root/'MANIFEST.json',root/'composer.json',*sorted((root/'contracts').glob('*.json'))]
for path in files:
    try:
        json.loads(path.read_text(encoding='utf-8'))
    except Exception as exc:
        print(f'INVALID JSON {path.relative_to(root)}: {exc}',file=sys.stderr); sys.exit(1)
print(f'JSON validation passed: {len(files)} files.')
