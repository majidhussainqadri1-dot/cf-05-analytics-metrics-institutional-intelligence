#!/usr/bin/env bash
set -euo pipefail
export TERM=dumb
cd "$(dirname "$0")/.."
find . -path './.git' -prune -o -path './build' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null
php tests/run.php
php tests/privacy-policy.php
php tests/future40.php
python3 scripts/validate-json.py
python3 scripts/architecture-check.py
python3 scripts/cross-plan-check.py
python3 scripts/schema-contract-check.py
python3 scripts/future40-check.py
python3 scripts/security-static-check.py
python3 scripts/secret-scan.py
printf 'CF-05 source QA passed.
'
