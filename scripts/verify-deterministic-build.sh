#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
version="$(php -r '$s=file_get_contents("sabri-analytics-institutional-intelligence.php"); if (!preg_match("/define\\(\x27SMAI_VERSION\x27,\\s*\x27([^\x27]+)\x27\\);/", $s, $m)) { exit(2); } echo $m[1];')"
archive="build/dist/CF-05-sabri-analytics-institutional-intelligence-${version}.zip"
first_copy="/tmp/cf05-first-${version}.zip"
trap 'rm -f "$first_copy"' EXIT
rm -rf build/dist
bash scripts/build-package.sh >/dev/null
first=$(sha256sum "$archive" | awk '{print $1}')
cp "$archive" "$first_copy"
rm -rf build/dist
bash scripts/build-package.sh >/dev/null
second=$(sha256sum "$archive" | awk '{print $1}')
test "$first" = "$second"
cmp -s "$first_copy" "$archive"
python3 scripts/package-parity.py
printf 'Deterministic package verified: %s\n' "$second"
