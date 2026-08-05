#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/dist
mkdir -p build/dist
python3 scripts/build-package.py
unzip -t build/dist/*.zip >/dev/null
