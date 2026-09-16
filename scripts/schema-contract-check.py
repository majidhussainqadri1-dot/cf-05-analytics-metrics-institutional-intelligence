#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCHEMA = (ROOT / 'src/Infrastructure/SchemaMigrator.php').read_text(encoding='utf-8')
EVENTS = (ROOT / 'src/Domain/EventIngestionService.php').read_text(encoding='utf-8')
EXPERIMENTS = (ROOT / 'src/Domain/ExperimentService.php').read_text(encoding='utf-8')
PLUGIN = (ROOT / 'sabri-analytics-institutional-intelligence.php').read_text(encoding='utf-8')
MANIFEST = json.loads((ROOT / 'MANIFEST.json').read_text(encoding='utf-8'))
BUILD = (ROOT / 'scripts/build-package.py').read_text(encoding='utf-8')
VERIFY = (ROOT / 'scripts/verify-deterministic-build.sh').read_text(encoding='utf-8')


def table_columns(name: str) -> set[str]:
    match = re.search(
        rf'CREATE TABLE \{{\$p\}}{re.escape(name)} \((.*?)\n\s*\) \{{\$charset\}};',
        SCHEMA,
        re.S,
    )
    if not match:
        raise AssertionError(f'missing CREATE TABLE for {name}')
    columns: set[str] = set()
    for raw in match.group(1).splitlines():
        line = raw.strip().rstrip(',')
        if not line or line.startswith(('PRIMARY ', 'UNIQUE ', 'KEY ', 'CONSTRAINT ', 'FOREIGN ')):
            continue
        token = line.split(None, 1)[0].strip('`')
        if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', token):
            columns.add(token)
    return columns


def raw_insert_columns(text: str, table_marker: str) -> set[str]:
    match = re.search(
        rf'INSERT\s+(?:IGNORE\s+)?INTO\s+`?\{{\${table_marker}\}}`?\s*\(([^)]+)\)',
        text,
        re.I | re.S,
    )
    if not match:
        raise AssertionError(f'missing raw INSERT column list for {table_marker}')
    return {part.strip().strip('`') for part in match.group(1).split(',') if part.strip()}


def experiment_insert_columns() -> set[str]:
    marker = "$inserted = $wpdb->insert($table, ["
    start = EXPERIMENTS.find(marker)
    if start < 0:
        raise AssertionError('missing experiment_facts insert')
    end = EXPERIMENTS.find(']);', start)
    if end < 0:
        raise AssertionError('unterminated experiment_facts insert')
    block = EXPERIMENTS[start:end]
    return set(re.findall(r"'([A-Za-z_][A-Za-z0-9_]*)'\s*=>", block))


def const(name: str) -> str:
    match = re.search(rf"define\('{re.escape(name)}',\s*'([^']+)'\);", PLUGIN)
    if not match:
        raise AssertionError(f'missing {name}')
    return match.group(1)


def main() -> int:
    failures: list[str] = []

    try:
        event_schema = table_columns('events')
        event_insert = raw_insert_columns(EVENTS, 'table')
        missing = sorted(event_insert - event_schema)
        if missing:
            failures.append('events schema missing insert columns: ' + ', '.join(missing))
        for required in ('source_sequence_key', 'region_code', 'provider_id', 'guardian_consent_version'):
            if required not in event_schema:
                failures.append(f'events schema missing governed column: {required}')
        if 'UNIQUE KEY source_sequence_key (source_sequence_key)' not in SCHEMA:
            failures.append('events schema lacks unique replay/sequence key')
    except AssertionError as error:
        failures.append(str(error))

    try:
        experiment_schema = table_columns('experiment_facts')
        experiment_insert = experiment_insert_columns()
        missing = sorted(experiment_insert - experiment_schema)
        if missing:
            failures.append('experiment_facts schema missing insert columns: ' + ', '.join(missing))
        for required in ('experiment_subject', 'deletion_key'):
            if required not in experiment_schema:
                failures.append(f'experiment_facts schema missing governed column: {required}')
            if required not in experiment_insert:
                failures.append(f'experiment_facts insert missing governed column: {required}')
        if 'KEY deletion_key (deletion_key)' not in SCHEMA:
            failures.append('experiment_facts schema lacks deletion-key index')
        for marker in ('legacy_experiment_facts_missing_deletion_key', 'source_sequence_key=SHA2'):
            if marker not in SCHEMA:
                failures.append(f'migration integrity guard missing: {marker}')
    except AssertionError as error:
        failures.append(str(error))

    try:
        version = const('SMAI_VERSION')
        schema_version = const('SMAI_SCHEMA_VERSION')
        contract_version = const('SMAI_CONTRACT_VERSION')
        expected_pairs = {
            'MANIFEST version': (str(MANIFEST.get('version', '')), version),
            'MANIFEST schema': (str(MANIFEST.get('schema_version', '')), schema_version),
            'MANIFEST contract': (str(MANIFEST.get('contract_version', '')), contract_version),
        }
        for label, (actual, expected) in expected_pairs.items():
            if actual != expected:
                failures.append(f'{label} mismatch: {actual!r} != {expected!r}')
        if f"version='{version}'" not in BUILD:
            failures.append('build-package.py version differs from plugin version')
        if f"'schema_version':'{schema_version}'" not in BUILD:
            failures.append('build-package.py schema version differs from plugin schema version')
        expected_archive = f'CF-05-sabri-analytics-institutional-intelligence-{version}.zip'
        if expected_archive not in VERIFY:
            failures.append('deterministic build verifier targets a different release archive')
    except AssertionError as error:
        failures.append(str(error))

    if failures:
        for failure in failures:
            print('FAIL:', failure, file=sys.stderr)
        return 1

    print('Schema/write/release consistency verified.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
