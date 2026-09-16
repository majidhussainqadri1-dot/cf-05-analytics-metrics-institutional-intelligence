#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def write(path: str, text: str) -> None:
    (ROOT / path).write_text(text, encoding='utf-8')


def replace_once(path: str, old: str, new: str, label: str) -> None:
    text = read(path)
    if old not in text:
        if new in text:
            return
        raise SystemExit(f'finalize-coding failed: missing anchor {label} in {path}')
    write(path, text.replace(old, new, 1))


def replace_all(path: str, old: str, new: str) -> None:
    text = read(path)
    if old in text:
        write(path, text.replace(old, new))


def patch_schema() -> None:
    path = 'src/Infrastructure/SchemaMigrator.php'
    replace_once(
        path,
        '            source_sequence bigint unsigned NULL,\n            occurred_at datetime NOT NULL,',
        '            source_sequence bigint unsigned NULL,\n            source_sequence_key char(64) NULL,\n            occurred_at datetime NOT NULL,',
        'events source_sequence_key column',
    )
    replace_once(
        path,
        '            guardian_consent_version varchar(64) NULL,\n            policy_version varchar(64) NULL,',
        '            guardian_consent_version varchar(64) NULL,\n            region_code varchar(32) NULL,\n            provider_id varchar(100) NULL,\n            policy_version varchar(64) NULL,',
        'events region/provider columns',
    )
    replace_once(
        path,
        '            UNIQUE KEY event_id (event_id),\n            KEY contract (event_name,event_version),',
        '            UNIQUE KEY event_id (event_id),\n            UNIQUE KEY source_sequence_key (source_sequence_key),\n            KEY contract (event_name,event_version),',
        'events sequence replay unique key',
    )
    replace_once(
        path,
        '            subject_ref char(64) NULL,\n            experiment_subject char(64) NULL,\n            variant_key varchar(100) NOT NULL,',
        '            subject_ref char(64) NULL,\n            experiment_subject char(64) NULL,\n            deletion_key char(64) NULL,\n            variant_key varchar(100) NOT NULL,',
        'experiment deletion key column',
    )
    replace_once(
        path,
        '            KEY subject_ref (subject_ref),\n            KEY experiment_subject (experiment_subject)\n        ) {$charset};";',
        '            KEY subject_ref (subject_ref),\n            KEY experiment_subject (experiment_subject),\n            KEY deletion_key (deletion_key)\n        ) {$charset};";',
        'experiment deletion key index',
    )

    migration_anchor = '''            if ($inserted === false) {
                throw new \\RuntimeException('CF-05 audit-state initialization failed: ' . (string) $wpdb->last_error);
            }

            update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
'''
    migration_block = '''            if ($inserted === false) {
                throw new \\RuntimeException('CF-05 audit-state initialization failed: ' . (string) $wpdb->last_error);
            }

            // Upgrade integrity: historical sequence identities can be reconstructed,
            // but legacy experiment facts without a deletion key cannot satisfy a
            // privacy-erasure request and therefore fail closed until rebuilt.
            $duplicateSequence = $wpdb->get_var(
                "SELECT 1 FROM {$p}events WHERE source_sequence IS NOT NULL GROUP BY source_module,source_environment,source_sequence HAVING COUNT(*) > 1 LIMIT 1"
            );
            if ($duplicateSequence !== null) {
                throw new \\RuntimeException('CF-05 migration blocked: duplicate historical source sequence identities require governed repair.');
            }
            $sequenceBackfill = $wpdb->query(
                "UPDATE {$p}events SET source_sequence_key=SHA2(CONCAT(source_module,'|',source_environment,'|',source_sequence),256) WHERE source_sequence IS NOT NULL AND source_sequence_key IS NULL"
            );
            if ($sequenceBackfill === false) {
                throw new \\RuntimeException('CF-05 source-sequence identity backfill failed: ' . (string) $wpdb->last_error);
            }

            $subjectBackfill = $wpdb->query(
                "UPDATE {$p}experiment_facts SET experiment_subject=subject_ref WHERE experiment_subject IS NULL AND subject_ref IS NOT NULL"
            );
            if ($subjectBackfill === false) {
                throw new \\RuntimeException('CF-05 experiment subject backfill failed: ' . (string) $wpdb->last_error);
            }
            $legacyExperimentFactsMissingDeletionKey = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}experiment_facts WHERE deletion_key IS NULL OR deletion_key=''"
            );
            if ($legacyExperimentFactsMissingDeletionKey > 0) {
                update_option('smai_experiment_rebuild_required', [
                    'code' => 'legacy_experiment_facts_missing_deletion_key',
                    'row_count' => $legacyExperimentFactsMissingDeletionKey,
                    'target_schema_version' => SMAI_SCHEMA_VERSION,
                    'detected_at' => gmdate('c'),
                ], false);
                throw new \\RuntimeException('CF-05 migration blocked: legacy experiment facts require privacy-safe rebuild before schema activation.');
            }
            delete_option('smai_experiment_rebuild_required');

            update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
'''
    text = read(path)
    if 'legacy_experiment_facts_missing_deletion_key' not in text:
        if migration_anchor not in text:
            raise SystemExit('finalize-coding failed: missing migration integrity anchor')
        write(path, text.replace(migration_anchor, migration_block, 1))


def patch_experiment_assignment() -> None:
    path = 'src/Domain/ExperimentService.php'
    replace_once(
        path,
        "                'subject_ref' => $canonical['subject_ref'],\n                'deletion_key' => $canonical['deletion_key'],",
        "                'subject_ref' => $canonical['subject_ref'],\n                'experiment_subject' => $canonical['subject_ref'],\n                'deletion_key' => $canonical['deletion_key'],",
        'experiment subject persistence',
    )


def patch_release_identity() -> None:
    replace_all('sabri-analytics-institutional-intelligence.php', '1.0.0-rc.4', '1.0.0-rc.5')
    replace_all('sabri-analytics-institutional-intelligence.php', "define('SMAI_SCHEMA_VERSION', '1.2.0');", "define('SMAI_SCHEMA_VERSION', '1.3.0');")

    manifest_path = ROOT / 'MANIFEST.json'
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    manifest['version'] = '1.0.0-rc.5'
    manifest['schema_version'] = '1.3.0'
    manifest['contract_version'] = '1.3.0'
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')

    replace_all('scripts/build-package.py', "version='1.0.0-rc.4'", "version='1.0.0-rc.5'")
    replace_all('scripts/build-package.py', "'schema_version':'1.2.0'", "'schema_version':'1.3.0'")
    replace_all('scripts/build-package.py', 'cf05-analytics-1-0-0-rc-4', 'cf05-analytics-1-0-0-rc-5')
    replace_all('scripts/verify-deterministic-build.sh', '1.0.0-rc.4', '1.0.0-rc.5')
    replace_all('scripts/verify-deterministic-build.sh', 'cf05-first-rc4.zip', 'cf05-first-rc5.zip')

    for path in ['README.md', 'readme.txt', 'docs/FORTY-ROUND-COMPLETION.md', 'docs/IMPLEMENTATION-STATUS.md']:
        replace_all(path, '1.0.0-rc.4', '1.0.0-rc.5')
    replace_all('docs/FORTY-ROUND-COMPLETION.md', 'schema: `1.2.0`', 'schema: `1.3.0`')
    replace_all('docs/IMPLEMENTATION-STATUS.md', 'schema `1.2.0`', 'schema `1.3.0`')

    completion = read('docs/CODING-COMPLETION-REPORT.md')
    completion = re.sub(r'- Candidate: `[^`]+`', '- Candidate: `1.0.0-rc.5`', completion, count=1)
    completion = re.sub(r'- Database schema: `[^`]+`', '- Database schema: `1.3.0`', completion, count=1)
    completion = re.sub(r'- Public contract family: `[^`]+`', '- Public contract family: `1.3.0`', completion, count=1)
    if 'Final coding closure — 2026-09-16' not in completion:
        completion += '''\n## Final coding closure — 2026-09-16\n\nThe final source-closure pass corrected schema/write drift for event replay identity, region/provider metadata and experiment deletion identity; added privacy-safe upgrade guards and legacy rebuild blocking; made the dedicated privacy-policy suite, three-plan consistency checks and schema/write/release consistency checks mandatory in QA; and removed release-candidate hard-coding from CI artifact verification. Source completion remains distinct from staging, live deployment and operation.\n'''
    write('docs/CODING-COMPLETION-REPORT.md', completion)

    changelog = read('CHANGELOG.md')
    if '## 1.0.0-rc.5 — 2026-09-16' not in changelog:
        block = '''# Changelog\n\n## 1.0.0-rc.5 — 2026-09-16\n\n- Closed remaining schema/write drift for event sequence identity, region/provider metadata and experiment deletion identity.\n- Added fail-closed upgrade checks for duplicate historical sequence identities and legacy experiment facts that cannot satisfy privacy deletion.\n- Made privacy-policy fixtures, three-plan consistency, and schema/write/release consistency mandatory QA gates.\n- Made CI artifact integrity derive the candidate version from source rather than a stale hard-coded release.\n- Raised database schema to `1.3.0`; public contract family remains `1.3.0`.\n\n'''
        if not changelog.startswith('# Changelog\n\n'):
            raise SystemExit('finalize-coding failed: unexpected changelog header')
        changelog = block + changelog[len('# Changelog\n\n'):]
        write('CHANGELOG.md', changelog)


def patch_quality_gates() -> None:
    qa = '''#!/usr/bin/env bash
set -euo pipefail
export TERM=dumb
cd "$(dirname "$0")/.."
find . -path './.git' -prune -o -path './build' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null
php tests/run.php
php tests/privacy-policy.php
python3 scripts/validate-json.py
python3 scripts/architecture-check.py
python3 scripts/cross-plan-check.py
python3 scripts/schema-contract-check.py
python3 scripts/security-static-check.py
python3 scripts/secret-scan.py
printf 'CF-05 source QA passed.\n'
'''
    write('scripts/qa.sh', qa)

    cross = read('scripts/cross-plan-check.py')
    cross = cross.replace("if manifest.get('contract_version') != '1.2.0':\n    errors.append('manifest contract version is not 1.2.0')", "if manifest.get('contract_version') != '1.3.0':\n    errors.append('manifest contract version is not 1.3.0')")
    cross = cross.replace("if manifest.get('version') != '1.0.0-rc.3':\n    errors.append('manifest version is not 1.0.0-rc.3')", "if manifest.get('version') != '1.0.0-rc.5':\n    errors.append('manifest version is not 1.0.0-rc.5')")
    write('scripts/cross-plan-check.py', cross)

    schema_check = read('scripts/schema-contract-check.py')
    old = '''        for required in ('experiment_subject', 'deletion_key'):
            if required not in experiment_schema:
                failures.append(f'experiment_facts schema missing governed column: {required}')
        if 'KEY deletion_key (deletion_key)' not in SCHEMA:
            failures.append('experiment_facts schema lacks deletion-key index')
'''
    new = '''        for required in ('experiment_subject', 'deletion_key'):
            if required not in experiment_schema:
                failures.append(f'experiment_facts schema missing governed column: {required}')
            if required not in experiment_insert:
                failures.append(f'experiment_facts insert missing governed column: {required}')
        if 'KEY deletion_key (deletion_key)' not in SCHEMA:
            failures.append('experiment_facts schema lacks deletion-key index')
        for marker in ('legacy_experiment_facts_missing_deletion_key', 'source_sequence_key=SHA2'):
            if marker not in SCHEMA:
                failures.append(f'migration integrity guard missing: {marker}')
'''
    if old in schema_check:
        schema_check = schema_check.replace(old, new, 1)
    elif new not in schema_check:
        raise SystemExit('finalize-coding failed: schema-contract-check anchor missing')
    write('scripts/schema-contract-check.py', schema_check)


def main() -> None:
    patch_schema()
    patch_experiment_assignment()
    patch_release_identity()
    patch_quality_gates()
    print('CF-05 final coding corrections applied for 1.0.0-rc.5 / schema 1.3.0.')


if __name__ == '__main__':
    main()
