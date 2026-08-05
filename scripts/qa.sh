#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
echo '[1/6] PHP syntax'
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -path './build' -prune -o -path './vendor' -prune -o -name '*.php' -print0)
echo '[2/6] Executable tests'
php tests/run.php
echo '[3/6] JSON contracts and manifests'
python3 scripts/validate-json.py
echo '[4/6] Architecture and requirement traceability'
python3 scripts/architecture-check.py
echo '[5/6] Secret scan'
python3 scripts/secret-scan.py
echo '[6/6] Release identity'
grep -q "Version:     1.0.0-rc.2" sabri-analytics-institutional-intelligence.php
grep -q '"version": "1.0.0-rc.2"' MANIFEST.json
printf 'CF-05 source QA passed.\n'
