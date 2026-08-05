#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
echo '[1/8] PHP syntax'
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -path './build' -prune -o -path './vendor' -prune -o -name '*.php' -print0)
echo '[2/8] Executable domain tests'
php tests/run.php
echo '[3/8] Privacy/differencing policy tests'
php tests/privacy-policy.php
echo '[4/8] JSON contracts and manifests'
python3 scripts/validate-json.py
echo '[5/8] Architecture and requirement traceability'
python3 scripts/architecture-check.py
echo '[6/8] Three-plan consistency'
python3 scripts/cross-plan-check.py
echo '[7/8] Secret scan'
python3 scripts/secret-scan.py
echo '[8/8] Release identity'
grep -q "Version:     1.0.0-rc.3" sabri-analytics-institutional-intelligence.php
grep -q "SMAI_CONTRACT_VERSION', '1.2.0" sabri-analytics-institutional-intelligence.php
grep -q '"version": "1.0.0-rc.3"' MANIFEST.json
grep -q '"contract_version": "1.2.0"' MANIFEST.json
printf 'CF-05 three-plan source QA passed.\n'
