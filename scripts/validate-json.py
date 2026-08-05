#!/usr/bin/env python3
import json, pathlib, sys
root=pathlib.Path(__file__).resolve().parents[1]
errors=[]
for p in sorted(root.rglob('*.json')):
    if 'build' in p.parts: continue
    try: json.loads(p.read_text())
    except Exception as e: errors.append(f'{p.relative_to(root)}: {e}')
if errors:
    print('\n'.join('ERROR: '+e for e in errors), file=sys.stderr); sys.exit(1)
print('JSON validation passed.')
