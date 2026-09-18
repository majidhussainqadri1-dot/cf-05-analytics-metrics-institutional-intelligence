#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/dist
bash scripts/build-package.sh >/dev/null
archive="build/dist/CF-05-sabri-analytics-institutional-intelligence-1.0.0-rc.9.zip"
first=$(sha256sum "$archive" | awk '{print $1}')
cp "$archive" /tmp/cf05-first-rc9.zip
rm -rf build/dist
bash scripts/build-package.sh >/dev/null
second=$(sha256sum "$archive" | awk '{print $1}')
test "$first" = "$second"
cmp -s /tmp/cf05-first-rc9.zip "$archive"
python3 scripts/package-parity.py
printf 'Deterministic package verified: %s\n' "$second"
