#!/usr/bin/env python3
from __future__ import annotations
import pathlib,re,sys
root=pathlib.Path(__file__).resolve().parents[1]
php=[p for p in root.rglob('*.php') if '.git' not in p.parts and 'build' not in p.parts]
forbidden={
 'dynamic-code':re.compile(r'\b(?:eval|assert)\s*\('),
 'shell-execution':re.compile(r'(?<!->)(?<!::)\b(?:shell_exec|exec|passthru|proc_open|popen|system)\s*\('),
 'unsafe-deserialization':re.compile(r'\bunserialize\s*\('),
 'unsafe-redirect':re.compile(r'\bwp_redirect\s*\('),
 'plain-http':re.compile(r'["\']http://'),
}
fail=[]
for path in php:
 text=path.read_text(encoding='utf-8')
 for label,pattern in forbidden.items():
  for match in pattern.finditer(text):
   line=text[text.rfind('\n',0,match.start())+1:text.find('\n',match.start()) if text.find('\n',match.start())!=-1 else len(text)].strip()
   if line.startswith(('public function ','private function ','protected function ','function ')):
    continue
   fail.append(f'{path.relative_to(root)}:{label}')
   break
if fail:
 print('\n'.join(fail),file=sys.stderr);sys.exit(1)
print(f'Security primitive scan passed: {len(php)} PHP files.')
