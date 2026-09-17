#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
s=(r/'src/Domain/FutureFeatureService.php').read_text(encoding='utf-8')
g=(r/'src/Domain/FutureFeatureRegistry.php').read_text(encoding='utf-8')
e=[]
if "CF05-FUT-038','Analytics Transparency Center','ai_intelligence','NEXT','standard','smai_view_transparency'" in g:e.append('FUT-038 persistent mutation is still authorized by a read-only capability')
if "requiredCapability=(string)($definition['capability']??'')" not in s:e.append('FutureFeatureService run lacks domain-layer capability enforcement')
if "smai_manage_future_intelligence')) return new WP_Error('smai_future_forbidden'" not in s:e.append('future configuration lacks domain-layer capability enforcement')
if "array_diff(array_keys($payload),['summary','severity','evidence'])" not in s:e.append('incident schema silently accepts unsupported fields')
marker="if(!FutureActivationService::isApproved()||!RuntimeGate::queryEnabled()||!RuntimeGate::schemaReady())"
if marker not in s:e.append('scheduled Future-40 execution does not recheck gates after feature lock')
if e:print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Future-40 authorization invariants check passed.')
