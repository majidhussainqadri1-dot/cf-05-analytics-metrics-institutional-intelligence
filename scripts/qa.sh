#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

find . -type f -name '*.php' -not -path './vendor/*' -print0 | sort -z | xargs -0 -n1 php -l
php tests/run.php
php -r '$files=["MANIFEST.json","contracts/event-envelope.schema.json","contracts/metric-response.schema.json","composer.json"]; foreach($files as $f){json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo "JSON OK: $f\n";}'

if grep -RInE '(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,}|SMAI_(INGESTION_SECRET|PSEUDONYM_KEY)[[:space:]]*=)' --exclude-dir=.git .; then
  echo 'Potential secret material detected.' >&2
  exit 1
fi

echo 'CF-05 QA completed successfully.'
