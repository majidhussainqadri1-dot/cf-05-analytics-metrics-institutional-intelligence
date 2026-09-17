#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

# PrivacyGateway: unknown fields must quarantine, required fields must be enforced,
# and aggregate-only minor handling must not retain individual/pseudonymous refs.
p=root/'src/Domain/PrivacyGateway.php'
s=p.read_text(encoding='utf-8')
s=s.replace("        $properties = [];\n        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];\n        $incoming = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];\n        foreach ($incoming as $name => $value) {\n            if (!is_string($name) || !isset($fields[$name]) || !is_array($fields[$name])) {\n                continue;\n            }\n            $normalized = $this->normalize((string) ($fields[$name]['type'] ?? ''), $value, $fields[$name], $name, $errors);",
"        $properties = [];\n        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];\n        $incoming = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];\n        $minorPolicy = (string) ($schema['minor_policy'] ?? 'aggregate_only');\n        $minorAggregateOnly = ($payload['is_minor'] ?? false) === true && $minorPolicy === 'aggregate_only';\n        foreach ($incoming as $name => $value) {\n            if (!is_string($name) || !isset($fields[$name]) || !is_array($fields[$name])) {\n                $errors[] = 'unknown_field_' . (is_string($name) ? $name : 'non_string');\n                continue;\n            }\n            if ($minorAggregateOnly && (string) ($fields[$name]['type'] ?? '') === 'pseudonymous_ref') {\n                continue;\n            }\n            $normalized = $this->normalize((string) ($fields[$name]['type'] ?? ''), $value, $fields[$name], $name, $errors);",1)
s=s.replace("        if (($schema['requires_consent'] ?? false) === true && ($payload['consent_granted'] ?? false) !== true) {",
"        foreach ($fields as $name => $definition) {\n            if (is_string($name) && is_array($definition) && ($definition['required'] ?? false) === true && !array_key_exists($name, $incoming)) {\n                $errors[] = 'missing_required_' . $name;\n            }\n        }\n        if (($schema['requires_consent'] ?? false) === true && ($payload['consent_granted'] ?? false) !== true) {",1)
s=s.replace("        $minorPolicy = (string) ($schema['minor_policy'] ?? 'aggregate_only');\n        if (($payload['is_minor'] ?? false) === true && $minorPolicy === 'deny') {",
"        if (($payload['is_minor'] ?? false) === true && $minorPolicy === 'deny') {",1)
s=s.replace("            'actor_ref' => $this->pseudonymizeNullable($payload['actor_ref'] ?? null, 'actor'),\n            'object_ref' => $this->pseudonymizeNullable($payload['object_ref'] ?? null, 'object'),",
"            'actor_ref' => $minorAggregateOnly ? null : $this->pseudonymizeNullable($payload['actor_ref'] ?? null, 'actor'),\n            'object_ref' => $minorAggregateOnly ? null : $this->pseudonymizeNullable($payload['object_ref'] ?? null, 'object'),",1)
p.write_text(s,encoding='utf-8')

# Event schema registration: domain write and audit evidence commit atomically.
p=root/'src/Domain/EventSchemaRegistry.php'
s=p.read_text(encoding='utf-8')
old="""        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($table, [
            'event_name' => $normalized['event_name'],
            'event_version' => $normalized['event_version'],
            'owner_module' => $normalized['owner_module'],
            'state' => 'proposed',
            'purpose' => $normalized['purpose'],
            'privacy_class' => $normalized['privacy_class'],
            'retention_days' => (int) $normalized['retention_days'],
            'deletion_key_field' => $normalized['deletion_key_field'],
            'schema_json' => $json,
            'schema_hash' => $hash,
            'row_version' => 1,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_schema_store_failed', 'Event schema could not be stored.', ['status' => 500]);
        }
        $id = (int) $this->db->wpdb()->insert_id;
        $this->audit->log('event_schema_registered', 'event_schema', (string) $id, 'success', ['event_name' => $normalized['event_name'], 'event_version' => $normalized['event_version'], 'schema_hash' => $hash], 'analytics_governance', null, $actorUserId);
        return ['id' => $id, 'status' => 'proposed', 'row_version' => 1, 'schema_hash' => $hash];
"""
new="""        $now = $this->db->now();
        $wpdb = $this->db->wpdb();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smai_schema_transaction_failed', 'Event schema transaction could not start.', ['status' => 500]);
        }
        try {
            $ok = $wpdb->insert($table, [
                'event_name' => $normalized['event_name'],
                'event_version' => $normalized['event_version'],
                'owner_module' => $normalized['owner_module'],
                'state' => 'proposed',
                'purpose' => $normalized['purpose'],
                'privacy_class' => $normalized['privacy_class'],
                'retention_days' => (int) $normalized['retention_days'],
                'deletion_key_field' => $normalized['deletion_key_field'],
                'schema_json' => $json,
                'schema_hash' => $hash,
                'row_version' => 1,
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ok !== 1) {
                throw new \\RuntimeException('Event schema could not be stored.');
            }
            $id = (int) $wpdb->insert_id;
            if (!$this->audit->logInOpenTransaction('event_schema_registered', 'event_schema', (string) $id, 'success', ['event_name' => $normalized['event_name'], 'event_version' => $normalized['event_version'], 'schema_hash' => $hash], 'analytics_governance', null, $actorUserId)) {
                throw new \\RuntimeException('Event schema audit evidence could not be stored.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \\RuntimeException('Event schema transaction could not be committed.');
            }
            return ['id' => $id, 'status' => 'proposed', 'row_version' => 1, 'schema_hash' => $hash];
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_schema_store_failed', 'Event schema and audit evidence were not committed.', ['status' => 500]);
        }
"""
if old not in s: raise SystemExit('registry target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# Event acceptance: event + pipeline job + audit evidence must commit together.
p=root/'src/Domain/EventIngestionService.php'
s=p.read_text(encoding='utf-8')
old="""            $job = (new JobQueue($this->db))->enqueue('pipeline.process_event', ['event_id' => $eventId], 'pipeline|' . $eventId);
            if (is_wp_error($job)) {
                throw new \\RuntimeException('Pipeline job could not be recorded atomically.');
            }
            $wpdb->query('COMMIT');
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_event_store_failed', $error->getMessage(), ['status' => 500]);
        }

        $logged = $this->audit->log(
            'analytics_event_ingested',
            'analytics_event',
            $eventId,
            'accepted',
            [
                'event_name' => $event['event_name'],
                'event_version' => $event['event_version'],
                'source_module' => $event['source_module'],
                'source_version' => $event['source_version'],
                'service' => $service,
                'payload_hash' => $payloadHash,
                'is_late' => $isLate,
            ],
            (string) $event['purpose'],
            isset($event['trace_id']) ? (string) $event['trace_id'] : null,
            null,
            'service'
        );

        return [
            'event_id' => $eventId,
            'status' => $logged ? 'accepted' : 'accepted_audit_degraded',
"""
new="""            $job = (new JobQueue($this->db))->enqueue('pipeline.process_event', ['event_id' => $eventId], 'pipeline|' . $eventId);
            if (is_wp_error($job)) {
                throw new \\RuntimeException('Pipeline job could not be recorded atomically.');
            }
            if (!$this->audit->logInOpenTransaction(
                'analytics_event_ingested', 'analytics_event', $eventId, 'accepted',
                ['event_name'=>$event['event_name'],'event_version'=>$event['event_version'],'source_module'=>$event['source_module'],'source_version'=>$event['source_version'],'service'=>$service,'payload_hash'=>$payloadHash,'is_late'=>$isLate],
                (string)$event['purpose'], isset($event['trace_id']) ? (string)$event['trace_id'] : null, null, 'service'
            )) {
                throw new \\RuntimeException('Event audit evidence could not be recorded atomically.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \\RuntimeException('Event transaction could not be committed.');
            }
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_event_store_failed', 'Event, pipeline and audit evidence were not committed.', ['status' => 500]);
        }

        return [
            'event_id' => $eventId,
            'status' => 'accepted',
"""
if old not in s: raise SystemExit('ingestion audit target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# Permanent regression gate.
(root/'scripts/event-privacy-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
pg=(root/'src/Domain/PrivacyGateway.php').read_text(encoding='utf-8')
ing=(root/'src/Domain/EventIngestionService.php').read_text(encoding='utf-8')
reg=(root/'src/Domain/EventSchemaRegistry.php').read_text(encoding='utf-8')
errors=[]
for token,msg in [
    ("unknown_field_",'unknown event properties are not fail-closed'),
    ("missing_required_",'required event properties are not enforced'),
    ("$minorAggregateOnly ? null",'minor aggregate-only path retains direct pseudonymous refs')]:
    if token not in pg: errors.append(msg)
if "logInOpenTransaction" not in ing or "accepted_audit_degraded" in ing: errors.append('event acceptance is not audit-atomic')
if "logInOpenTransaction('event_schema_registered'" not in reg: errors.append('event schema registration is not audit-atomic')
if errors:
    print('\\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Event/privacy invariants check passed.')
""",encoding='utf-8')
p=root/'scripts/qa.sh'; s=p.read_text(encoding='utf-8')
needle='python3 scripts/authorization-invariants-check.py\n'
if 'event-privacy-invariants-check.py' not in s:
    if needle not in s: raise SystemExit('qa insertion missing')
    p.write_text(s.replace(needle,needle+'python3 scripts/event-privacy-invariants-check.py\n',1),encoding='utf-8')

(root/'docs/REVIEW-ROUND-12.md').write_text("""# Review Round 12 — Event Ingestion and Privacy Gateway\n\nThe review was completed against CF05-FR-001..006 and the amended CF-05 privacy/unknown-field acceptance rules before any corrections began.\n\n## Confirmed defects\n1. Unknown event property fields were silently dropped instead of failing closed into quarantine.\n2. Schema fields marked `required` were not rejected when absent.\n3. `minor_policy=aggregate_only` could retain actor/object and field-level pseudonymous references, weakening the promised aggregate-only boundary.\n4. Successful event ingestion committed the event/job before audit logging, allowing an `accepted_audit_degraded` state instead of atomic acceptance evidence.\n5. Event-schema registration persisted the contract independently from its audit record and ignored audit failure.\n\n## Corrections\nUnknown/missing fields now fail closed; minor aggregate-only processing removes individual/pseudonymous refs; accepted event + pipeline job + audit are transactional; schema registration + audit are transactional; permanent QA invariants were added.\n\nNo staging or live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 12 corrections applied.')
