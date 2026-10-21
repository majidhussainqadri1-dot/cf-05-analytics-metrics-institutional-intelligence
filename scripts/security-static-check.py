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
rest=(root/'src/Http/RestController.php').read_text(encoding='utf-8')
if "get_param('token')" in rest:
 fail.append('RestController:bearer-token-in-query')
for token in ["get_header('x-sabri-download-token')","get_header('x-sabri-report-token')","^/sabri-analytics/v1/exports/"]:
 if token not in rest:
  fail.append('RestController:missing-download-boundary:'+token)
idem=(root/'src/Infrastructure/IdempotencyGuard.php').read_text(encoding='utf-8')
for token in ["containsSecret","__encrypted","new CryptoBox(SMAI_EXPORT_KEY)","idempotency|"]:
 if token not in idem:
  fail.append('IdempotencyGuard:protected-replay-missing:'+token)
if "'response_json' => Json::encode($response)" in idem:
 fail.append('IdempotencyGuard:plaintext-response-persistence')
if fail:
 print('\n'.join(fail),file=sys.stderr);sys.exit(1)
print(f'Security primitive scan passed: {len(php)} PHP files.')
