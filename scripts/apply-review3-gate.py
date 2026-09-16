#!/usr/bin/env python3
from pathlib import Path
import re
p=Path('src/Domain/FutureFeatureService.php')
s=p.read_text(encoding='utf-8')
if 'use Sabri\\AnalyticsIntelligence\\Infrastructure\\FutureActivationService;' not in s:
    s=s.replace('use Sabri\\AnalyticsIntelligence\\Infrastructure\\Database;\n','use Sabri\\AnalyticsIntelligence\\Infrastructure\\Database;\nuse Sabri\\AnalyticsIntelligence\\Infrastructure\\FutureActivationService;\n')
s=s.replace('$this->future40ActivationApproved()', 'FutureActivationService::isApproved()')
s,n=re.subn(r"\n    private function future40ActivationApproved\(\):bool\n    \{.*?\n    \}\n\n    private function begin\(\):bool", "\n    private function begin():bool", s, count=1, flags=re.S)
if n!=1:
    raise SystemExit('future40ActivationApproved block not replaced exactly once')
p.write_text(s,encoding='utf-8')
print('Review-3 feature service activation gate patched.')
