#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/build-package.sh >/dev/null
first=$(sha256sum build/dist/*.zip | awk '{print $1}')
cp build/dist/*.zip /tmp/cf05-first.zip
bash scripts/build-package.sh >/dev/null
second=$(sha256sum build/dist/*.zip | awk '{print $1}')
test "$first" = "$second"
cmp -s /tmp/cf05-first.zip build/dist/*.zip
printf 'Deterministic package verified: %s\n' "$second"
