#!/usr/bin/env bash
set -euo pipefail
export TERM=dumb
cd "$(dirname "$0")/.."
find . -path './.git' -prune -o -path './build' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null
php tests/run.php
php tests/privacy-policy.php
php tests/future40.php
php tests/funnel-attribution.php
php tests/fresh-completeness-regression.php
php tests/sr230-269-privacy-measurement.php
php tests/sr270-309-privacy-ledger.php
php tests/sr310-349-dashboard-privacy.php
php tests/sr350-389-viewer-access-contract.php
php tests/sr390-429-privacy-job-integrity.php
php tests/fresh-privacy-rate-boundary-20261021.php
php tests/fresh-four-parser-parity-20261021.php
python3 scripts/validate-json.py
python3 scripts/architecture-check.py
python3 scripts/fresh40-plan-parity-20261021.py
python3 scripts/sr310-349-source-trace-check.py
python3 scripts/sr350-389-plan-scope-check.py
python3 scripts/cross-plan-check.py
python3 scripts/schema-contract-check.py
python3 scripts/public-contract-parity-check.py
python3 scripts/future40-check.py
python3 scripts/repository-hygiene-check.py
python3 scripts/release-governance-check.py
python3 scripts/authorization-invariants-check.py
python3 scripts/event-privacy-invariants-check.py
python3 scripts/metric-privacy-invariants-check.py
python3 scripts/pipeline-quality-invariants-check.py
python3 scripts/reporting-governance-invariants-check.py
python3 scripts/experiment-governance-invariants-check.py
python3 scripts/deletion-retention-restore-invariants-check.py
python3 scripts/future40-authorization-invariants-check.py
python3 scripts/infrastructure-runtime-invariants-check.py
python3 scripts/review20-29-invariants-check.py
python3 scripts/review30-39-invariants-check.py
python3 scripts/review40-49-invariants-check.py
python3 scripts/review50-59-invariants-check.py
python3 scripts/security-static-check.py
python3 scripts/secret-scan.py
printf 'CF-05 source QA passed.
'
