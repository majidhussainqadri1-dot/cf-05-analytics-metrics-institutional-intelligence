#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import base64
import io
import re
import tarfile

ROOT = Path(__file__).resolve().parents[1]
CODEX = ROOT / '.codex'


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'CF-05 review apply failed: missing patch anchor: {label}')
    return text.replace(old, new, 1)


def recover_archive() -> bytes:
    parts = sorted(CODEX.glob('review40-part-*'))
    if not parts:
        raise SystemExit('CF-05 review apply failed: no review40 payload parts found')

    chunks: list[tuple[str, str]] = []
    for part in parts:
        text = ''.join(part.read_text(encoding='utf-8').split())
        if not re.fullmatch(r'[A-Za-z0-9+/=]*', text):
            raise SystemExit(f'CF-05 review apply failed: invalid base64 characters in {part.name}')
        chunks.append((part.name, text))

    merged = chunks[0][1]
    print(f'{chunks[0][0]}: {len(chunks[0][1])} chars')
    for name, chunk in chunks[1:]:
        overlap = 0
        for candidate in range(min(len(merged), len(chunk)), 63, -1):
            if merged.endswith(chunk[:candidate]):
                overlap = candidate
                break
        print(f'{name}: {len(chunk)} chars; overlap removed={overlap}')
        merged += chunk[overlap:]

    if '=' in merged[:-4]:
        raise SystemExit('CF-05 review apply failed: unexpected base64 padding before payload tail')
    merged += '=' * ((-len(merged)) % 4)
    return base64.b64decode(merged, validate=True)


def safe_extract(archive: bytes) -> None:
    narrative = (ROOT / 'src/Domain/NarrativeService.php').read_bytes()
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tf:
        members = tf.getmembers()
        for member in members:
            target = (ROOT / member.name).resolve()
            if ROOT not in target.parents and target != ROOT:
                raise SystemExit(f'CF-05 review apply failed: unsafe archive path: {member.name}')
            if member.issym() or member.islnk():
                raise SystemExit(f'CF-05 review apply failed: archive links are not allowed: {member.name}')
        print(f'Extracting {len(members)} reviewed payload members')
        tf.extractall(ROOT)

    # One historical payload member was corrupt. The replacement service was
    # independently reconstructed from the governing narrative requirement.
    (ROOT / 'src/Domain/NarrativeService.php').write_bytes(narrative)


def patch_schema_migrator() -> None:
    path = ROOT / 'src/Infrastructure/SchemaMigrator.php'
    text = path.read_text(encoding='utf-8')

    if 'guardian_consent_version' not in text:
        text = replace_once(
            text,
            '            consent_version varchar(64) NULL,\n            policy_version varchar(64) NULL,',
            '            consent_version varchar(64) NULL,\n            guardian_consent_version varchar(64) NULL,\n            policy_version varchar(64) NULL,',
            'guardian consent column',
        )

    if 'experiment_subject' not in text:
        text = replace_once(
            text,
            '            subject_ref char(64) NOT NULL,\n            variant_key varchar(100) NOT NULL,',
            '            subject_ref char(64) NULL,\n            experiment_subject char(64) NULL,\n            variant_key varchar(100) NOT NULL,',
            'experiment subject column',
        )
        text = replace_once(
            text,
            '            KEY subject_ref (subject_ref)\n        ) {$charset};";',
            '            KEY subject_ref (subject_ref),\n            KEY experiment_subject (experiment_subject)\n        ) {$charset};";',
            'experiment subject index',
        )

    if 'smai_schema_migration_error' not in text:
        old = '''        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$p}audit_state (id,last_hash,row_version,updated_at) VALUES (1,%s,1,%s)",
            str_repeat('0', 64),
            gmdate('Y-m-d H:i:s')
        ));

        update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
'''
        new = '''        update_option('smai_schema_migration_error', [
            'code' => 'migration_in_progress',
            'target_schema_version' => defined('SMAI_SCHEMA_VERSION') ? SMAI_SCHEMA_VERSION : 'undefined',
            'failed_at' => null,
            'started_at' => gmdate('c'),
        ], false);

        try {
            foreach ($sql as $statement) {
                $wpdb->last_error = '';
                dbDelta($statement);
                if ((string) $wpdb->last_error !== '') {
                    throw new \\RuntimeException('CF-05 schema migration failed: ' . (string) $wpdb->last_error);
                }
            }

            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}audit_state (id,last_hash,row_version,updated_at) VALUES (1,%s,1,%s)",
                str_repeat('0', 64),
                gmdate('Y-m-d H:i:s')
            ));
            if ($inserted === false) {
                throw new \\RuntimeException('CF-05 audit-state initialization failed: ' . (string) $wpdb->last_error);
            }

            update_option('smai_schema_version', SMAI_SCHEMA_VERSION, false);
            delete_option('smai_schema_migration_error');
        } catch (\\Throwable $error) {
            update_option('smai_schema_migration_error', [
                'code' => 'schema_migration_failed',
                'target_schema_version' => defined('SMAI_SCHEMA_VERSION') ? SMAI_SCHEMA_VERSION : 'undefined',
                'message_hash' => hash('sha256', $error->getMessage()),
                'failed_at' => gmdate('c'),
            ], false);
            throw $error;
        }
'''
        text = replace_once(text, old, new, 'migration failure state')

    path.write_text(text, encoding='utf-8')


def patch_event_ingestion() -> None:
    path = ROOT / 'src/Domain/EventIngestionService.php'
    text = path.read_text(encoding='utf-8')

    if "'guardian_consent_version' => 64" not in text:
        text = replace_once(
            text,
            "foreach (['consent_version' => 64, 'policy_version' => 64, 'trace_id' => 100] as $field => $maxLength)",
            "foreach (['consent_version' => 64, 'guardian_consent_version' => 64, 'policy_version' => 64, 'trace_id' => 100] as $field => $maxLength)",
            'guardian consent envelope validation',
        )

    if 'consent_version,guardian_consent_version,policy_version' not in text:
        text = replace_once(
            text,
            'purpose,consent_version,policy_version,trace_id,properties_json',
            'purpose,consent_version,guardian_consent_version,policy_version,trace_id,properties_json',
            'guardian consent event insert columns',
        )
        text = replace_once(
            text,
            'VALUES (%s,%s,%s,%s,%s,%s,NULLIF(%d,0),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)',
            'VALUES (%s,%s,%s,%s,%s,%s,NULLIF(%d,0),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)',
            'guardian consent event insert placeholders',
        )
        text = replace_once(
            text,
            "            isset($event['consent_version']) ? (string) $event['consent_version'] : null,\n            isset($event['policy_version']) ? (string) $event['policy_version'] : null,",
            "            isset($event['consent_version']) ? (string) $event['consent_version'] : null,\n            isset($event['guardian_consent_version']) ? (string) $event['guardian_consent_version'] : null,\n            isset($event['policy_version']) ? (string) $event['policy_version'] : null,",
            'guardian consent event insert value',
        )

    path.write_text(text, encoding='utf-8')


def patch_experiment_subject() -> None:
    path = ROOT / 'src/Domain/ExperimentService.php'
    text = path.read_text(encoding='utf-8')
    marker = "'experiment_subject' => $canonical['subject_ref']"
    if marker not in text:
        anchor = "            'subject_ref' => $canonical['subject_ref'],\n            'variant_key' => Text::truncate($canonical['variant_key'], 100),"
        if anchor in text:
            text = text.replace(
                anchor,
                "            'subject_ref' => $canonical['subject_ref'],\n            'experiment_subject' => $canonical['subject_ref'],\n            'variant_key' => Text::truncate($canonical['variant_key'], 100),",
                1,
            )
    path.write_text(text, encoding='utf-8')


def main() -> None:
    archive = recover_archive()
    safe_extract(archive)
    patch_schema_migrator()
    patch_event_ingestion()
    patch_experiment_subject()
    print('CF-05 forty-round payload recovered and governing schema/privacy patches applied.')


if __name__ == '__main__':
    main()
