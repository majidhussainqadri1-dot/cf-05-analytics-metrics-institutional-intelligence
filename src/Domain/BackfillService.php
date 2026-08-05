<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\JobQueue;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class BackfillService
{
    private Database $db;
    private AuditLogger $audit;
    private LineageService $lineage;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
        $this->lineage = new LineageService($db);
    }

    /** @param array<string,mixed> $definition */
    public function plan(string $datasetId, string $version, string $start, string $end, array $definition, int $actorUserId): array|WP_Error
    {
        $startDate = $this->normalizeDate($start);
        $endDate = $this->normalizeDate($end);
        if ($actorUserId < 1 || $startDate === null || $endDate === null || $startDate >= $endDate) {
            return new WP_Error('smai_invalid_backfill_window', 'Backfill window is invalid.', ['status' => 400]);
        }
        if ((strtotime($endDate) - strtotime($startDate)) > 366 * DAY_IN_SECONDS) {
            return new WP_Error('smai_backfill_window_too_large', 'Backfill window exceeds one year.', ['status' => 400]);
        }
        $dataset = $this->dataset($datasetId, $version);
        if ($dataset === null) {
            return new WP_Error('smai_dataset_not_found', 'Dataset was not found.', ['status' => 404]);
        }
        if (!in_array((string) $dataset['state'], ['draft','built','quality_validated','privacy_approved','published'], true)) {
            return new WP_Error('smai_dataset_not_buildable', 'Dataset state does not allow a backfill.', ['status' => 409]);
        }
        $allowed = ['reason','max_count_delta','require_hash_match','expected_source_events','estimated_cost_units'];
        if (array_diff(array_keys($definition), $allowed) !== [] || (new SensitiveValueDetector())->violations($definition) !== []) {
            return new WP_Error('smai_invalid_backfill_definition', 'Backfill definition contains unapproved or sensitive data.', ['status' => 400]);
        }
        $reason = Text::truncate(trim(wp_strip_all_tags((string) ($definition['reason'] ?? ''))), 500);
        if (strlen($reason) < 8) {
            return new WP_Error('smai_backfill_reason_required', 'A meaningful backfill reason is required.', ['status' => 400]);
        }
        $definition = [
            'reason' => $reason,
            'max_count_delta' => max(0, (int) ($definition['max_count_delta'] ?? PHP_INT_MAX)),
            'require_hash_match' => (bool) ($definition['require_hash_match'] ?? false),
            'expected_source_events' => isset($definition['expected_source_events']) ? max(0, (int) $definition['expected_source_events']) : null,
            'estimated_cost_units' => isset($definition['estimated_cost_units']) ? max(0, (int) $definition['estimated_cost_units']) : null,
        ];
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('backfills'), [
            'backfill_uuid' => $uuid,
            'dataset_id' => $datasetId,
            'dataset_version' => $version,
            'state' => 'planned',
            'date_start' => $startDate,
            'date_end' => $endDate,
            'definition_json' => Json::canonical($definition),
            'row_version' => 1,
            'requested_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            return new WP_Error('smai_backfill_store_failed', 'Backfill plan could not be stored.', ['status' => 500]);
        }
        $this->audit->log('backfill_planned', 'backfill', $uuid, 'success', ['dataset_ref' => $datasetId . '@' . $version, 'window_start' => $startDate, 'window_end' => $endDate], 'data_rebuild', null, $actorUserId);
        return ['backfill_uuid' => $uuid, 'state' => 'planned', 'row_version' => 1];
    }

    public function dryRun(string $uuid, int $actorUserId): array|WP_Error
    {
        $row = $this->backfill($uuid);
        if ($row === null || (string) $row['state'] !== 'planned') {
            return new WP_Error('smai_backfill_not_planned', 'Backfill is not in planned state.', ['status' => 409]);
        }
        $dataset = $this->dataset((string) $row['dataset_id'], (string) $row['dataset_version']);
        if ($dataset === null) {
            return new WP_Error('smai_dataset_not_found', 'Dataset was not found.', ['status' => 404]);
        }
        $sources = (array) (($dataset['definition']['sources'] ?? []));
        $count = $this->sourceCount($sources, (string) $row['date_start'], (string) $row['date_end']);
        $definition = Json::object((string) $row['definition_json']);
        $estimate = [
            'source_events' => $count,
            'estimated_rows' => $count,
            'estimated_storage_bytes' => $count * 1024,
            'expected_source_events' => $definition['expected_source_events'] ?? null,
            'count_delta_from_expected' => isset($definition['expected_source_events']) ? $count - (int) $definition['expected_source_events'] : null,
            'window_start' => $row['date_start'],
            'window_end' => $row['date_end'],
        ];
        $updated = $this->db->wpdb()->update($this->db->table('backfills'), [
            'state' => 'dry_run',
            'dry_run_json' => Json::encode($estimate),
            'row_version' => (int) $row['row_version'] + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'state' => 'planned', 'row_version' => (int) $row['row_version']]);
        if ($updated !== 1) {
            return new WP_Error('smai_backfill_conflict', 'Backfill changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('backfill_dry_run', 'backfill', $uuid, 'success', $estimate, 'data_rebuild', null, $actorUserId);
        return ['backfill_uuid' => $uuid, 'state' => 'dry_run', 'estimate' => $estimate, 'row_version' => (int) $row['row_version'] + 1];
    }

    public function approveAndQueue(string $uuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $row = $this->backfill($uuid);
        if ($row === null || (string) $row['state'] !== 'dry_run' || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_backfill_not_ready', 'Backfill is not ready or is stale.', ['status' => 409]);
        }
        if ((int) $row['requested_by'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Backfill requester cannot approve execution.', ['status' => 403]);
        }
        $definition = Json::object((string) $row['definition_json']);
        $dryRun = Json::object((string) $row['dry_run_json']);
        if (isset($definition['expected_source_events']) && abs((int) $dryRun['source_events'] - (int) $definition['expected_source_events']) > (int) $definition['max_count_delta']) {
            return new WP_Error('smai_backfill_dry_run_outside_tolerance', 'Dry-run source count is outside the approved tolerance.', ['status' => 409]);
        }
        $updated = $this->db->wpdb()->update($this->db->table('backfills'), [
            'state' => 'approved',
            'approved_by' => $actorUserId,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'state' => 'dry_run', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_backfill_conflict', 'Backfill changed concurrently.', ['status' => 409]);
        }
        $job = (new JobQueue($this->db))->enqueue('backfill.run', ['backfill_uuid' => $uuid, 'actor_user_id' => $actorUserId], 'backfill|' . $uuid . '|cursor|0');
        if (is_wp_error($job)) {
            $this->db->wpdb()->update($this->db->table('backfills'), ['state' => 'dry_run', 'approved_by' => null, 'row_version' => $expectedVersion + 2, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'approved']);
            return $job;
        }
        return ['backfill_uuid' => $uuid, 'state' => 'approved', 'row_version' => $expectedVersion + 1, 'job' => $job];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $uuid = (string) ($payload['backfill_uuid'] ?? '');
        $cursor = max(0, (int) ($payload['cursor_id'] ?? 0));
        $row = $this->backfill($uuid);
        if ($row === null || !in_array((string) $row['state'], ['approved','shadow_build'], true)) {
            throw new \RuntimeException('Backfill is not approved.');
        }
        $dataset = $this->dataset((string) $row['dataset_id'], (string) $row['dataset_version']);
        if ($dataset === null) {
            throw new \RuntimeException('Dataset is unavailable.');
        }
        $buildUuid = (string) ($row['build_uuid'] ?? '');
        if ($buildUuid === '') {
            $buildUuid = Uuid::v4();
            $now = $this->db->now();
            if ($this->db->wpdb()->insert($this->db->table('dataset_builds'), [
                'build_uuid' => $buildUuid,
                'dataset_id' => $row['dataset_id'],
                'dataset_version' => $row['dataset_version'],
                'state' => 'building',
                'is_active' => 0,
                'source_start' => $row['date_start'],
                'source_end' => $row['date_end'],
                'created_by' => (int) $row['requested_by'],
                'approved_by' => (int) $row['approved_by'],
                'checkpoint_json' => Json::encode(['cursor_id' => 0, 'processed' => 0]),
                'created_at' => $now,
                'updated_at' => $now,
            ]) !== 1) {
                throw new \RuntimeException('Shadow build could not be created.');
            }
            $updated = $this->db->wpdb()->update($this->db->table('backfills'), ['state' => 'shadow_build', 'build_uuid' => $buildUuid, 'updated_at' => $now], ['id' => (int) $row['id'], 'state' => 'approved']);
            if ($updated !== 1) {
                throw new \RuntimeException('Backfill changed while creating the shadow build.');
            }
        }

        $definition = (array) $dataset['definition'];
        $events = $this->sourceEventsBatch((array) ($definition['sources'] ?? []), (string) $row['date_start'], (string) $row['date_end'], $cursor, 1000);
        $inserted = 0;
        $lastId = $cursor;
        $rowTable = $this->db->table('dataset_rows');
        foreach ($events as $event) {
            $lastId = max($lastId, (int) $event['id']);
            if (!empty($event['correction_of_event_id'])) {
                $this->db->wpdb()->query($this->db->wpdb()->prepare("UPDATE `{$rowTable}` SET is_current=0,effective_to=%s WHERE build_uuid=%s AND source_event_id=%s AND is_current=1", $event['occurred_at'], $buildUuid, $event['correction_of_event_id']));
            }
            $mapped = (new TransformationEngine())->map($event, (array) ($definition['fields'] ?? []));
            $rowJson = Json::canonical($mapped);
            $rowHash = hash('sha256', (string) $event['event_id'] . '|' . $rowJson);
            $ok = $this->db->wpdb()->query($this->db->wpdb()->prepare(
                "INSERT IGNORE INTO `{$rowTable}` (build_uuid,dataset_id,dataset_version,source_event_id,source_object_ref,deletion_key,effective_from,effective_to,is_current,row_json,row_hash,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,NULL,1,%s,%s,%s)",
                $buildUuid, $row['dataset_id'], $row['dataset_version'], $event['event_id'], $event['object_ref'], $event['deletion_key'], $event['occurred_at'], $rowJson, $rowHash, $this->db->now()
            ));
            if ($ok === 1) {$inserted++;}
            $this->lineage->link('event', (string) $event['event_id'], (string) $event['event_version'], 'build', $buildUuid, (string) $row['dataset_version'], (string) $dataset['owner_module'], null, defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null);
        }
        $count = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$rowTable}` WHERE build_uuid=%s", $buildUuid));
        $checkpoint = ['cursor_id' => $lastId, 'processed' => $count, 'updated_at' => gmdate('c')];
        $this->db->wpdb()->update($this->db->table('dataset_builds'), ['row_count' => $count, 'checkpoint_json' => Json::encode($checkpoint), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']);
        if (count($events) === 1000) {
            $next = (new JobQueue($this->db))->enqueue('backfill.run', ['backfill_uuid' => $uuid, 'cursor_id' => $lastId, 'actor_user_id' => (int) ($payload['actor_user_id'] ?? 0)], 'backfill|' . $uuid . '|cursor|' . $lastId);
            if (is_wp_error($next)) {throw new \RuntimeException('Backfill continuation could not be queued.');}
            return ['backfill_uuid' => $uuid, 'build_uuid' => $buildUuid, 'state' => 'shadow_build', 'batch_inserted' => $inserted, 'processed' => $count, 'cursor_id' => $lastId, 'continuation' => $next];
        }

        $buildHash = $this->buildHash($buildUuid);
        $buildTable = $this->db->table('dataset_builds');
        $active = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT build_uuid,row_count,build_hash,source_start,source_end FROM `{$buildTable}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 LIMIT 1", $row['dataset_id'], $row['dataset_version']), ARRAY_A);
        $comparison = [
            'shadow_count' => $count,
            'active_count' => is_array($active) ? (int) $active['row_count'] : 0,
            'count_delta' => $count - (is_array($active) ? (int) $active['row_count'] : 0),
            'shadow_hash' => $buildHash,
            'active_hash' => is_array($active) ? $active['build_hash'] : null,
            'active_build_uuid' => is_array($active) ? $active['build_uuid'] : null,
            'same_window' => is_array($active) && (string) $active['source_start'] === (string) $row['date_start'] && (string) $active['source_end'] === (string) $row['date_end'],
            'source_window' => ['start' => $row['date_start'], 'end' => $row['date_end']],
        ];
        $this->db->wpdb()->update($buildTable, ['state' => 'compared', 'row_count' => $count, 'build_hash' => $buildHash, 'checkpoint_json' => Json::encode($checkpoint), 'comparison_json' => Json::encode($comparison), 'updated_at' => $this->db->now()], ['build_uuid' => $buildUuid, 'state' => 'building']);
        $this->db->wpdb()->update($this->db->table('backfills'), ['state' => 'compared', 'previous_build_uuid' => $comparison['active_build_uuid'], 'comparison_json' => Json::encode($comparison), 'row_version' => (int) $row['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'shadow_build']);
        return ['backfill_uuid' => $uuid, 'build_uuid' => $buildUuid, 'state' => 'compared', 'batch_inserted' => $inserted, 'processed' => $count, 'comparison' => $comparison];
    }

    public function activate(string $uuid, int $actorUserId): array|WP_Error
    {
        $row = $this->backfill($uuid);
        if ($row === null || (string) $row['state'] !== 'compared' || empty($row['build_uuid'])) {
            return new WP_Error('smai_backfill_not_compared', 'Backfill must be compared before activation.', ['status' => 409]);
        }
        if ((int) $row['approved_by'] === $actorUserId || (int) $row['requested_by'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Independent activation actor is required.', ['status' => 403]);
        }
        $definition = Json::object((string) $row['definition_json']);
        $comparison = Json::object((string) $row['comparison_json']);
        if (abs((int) ($comparison['count_delta'] ?? PHP_INT_MAX)) > (int) ($definition['max_count_delta'] ?? PHP_INT_MAX)) {
            return new WP_Error('smai_backfill_comparison_outside_tolerance', 'Backfill comparison is outside the approved count tolerance.', ['status' => 409]);
        }
        if (($definition['require_hash_match'] ?? false) === true && ($comparison['same_window'] ?? false) === true && !hash_equals((string) ($comparison['active_hash'] ?? ''), (string) ($comparison['shadow_hash'] ?? ''))) {
            return new WP_Error('smai_backfill_hash_mismatch', 'Backfill hash does not match the approved same-window source.', ['status' => 409]);
        }
        $wpdb = $this->db->wpdb();
        $buildTable = $this->db->table('dataset_builds');
        $wpdb->query('START TRANSACTION');
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->db->table('backfills')}` WHERE id=%d FOR UPDATE", (int) $row['id']), ARRAY_A);
            if (!is_array($locked) || (string) $locked['state'] !== 'compared') {throw new \RuntimeException('Backfill changed concurrently.');}
            $previous = $wpdb->get_var($wpdb->prepare("SELECT build_uuid FROM `{$buildTable}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 FOR UPDATE", $row['dataset_id'], $row['dataset_version']));
            $wpdb->query($wpdb->prepare("UPDATE `{$buildTable}` SET is_active=0,state=IF(state='active','superseded',state),updated_at=%s WHERE dataset_id=%s AND dataset_version=%s AND is_active=1", $this->db->now(), $row['dataset_id'], $row['dataset_version']));
            if ($wpdb->update($buildTable, ['is_active' => 1, 'state' => 'active', 'activated_at' => $this->db->now(), 'updated_at' => $this->db->now()], ['build_uuid' => $row['build_uuid'], 'state' => 'compared']) !== 1) {throw new \RuntimeException('Shadow build activation failed.');}
            if ($wpdb->update($this->db->table('backfills'), ['state' => 'activated', 'previous_build_uuid' => is_string($previous) ? $previous : null, 'activated_by' => $actorUserId, 'activated_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'compared', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \RuntimeException('Backfill activation record failed.');}
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_backfill_activation_failed', 'Backfill activation failed safely.', ['status' => 409]);
        }
        $this->audit->log('backfill_activated', 'backfill', $uuid, 'success', ['build_uuid' => $row['build_uuid']], 'data_rebuild', null, $actorUserId);
        return ['backfill_uuid' => $uuid, 'build_uuid' => $row['build_uuid'], 'state' => 'activated'];
    }

    public function rollback(string $uuid, int $actorUserId): array|WP_Error
    {
        $row = $this->backfill($uuid);
        if ($row === null || (string) $row['state'] !== 'activated' || empty($row['build_uuid']) || empty($row['previous_build_uuid'])) {
            return new WP_Error('smai_backfill_not_rollbackable', 'Backfill has no governed rollback target.', ['status' => 409]);
        }
        if (in_array($actorUserId, [(int) $row['requested_by'], (int) $row['approved_by'], (int) $row['activated_by']], true)) {
            return new WP_Error('smai_separation_of_duties', 'Independent rollback actor is required.', ['status' => 403]);
        }
        $wpdb = $this->db->wpdb();
        $buildTable = $this->db->table('dataset_builds');
        $wpdb->query('START TRANSACTION');
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->db->table('backfills')}` WHERE id=%d FOR UPDATE", (int) $row['id']), ARRAY_A);
            if (!is_array($locked) || (string) $locked['state'] !== 'activated') {throw new \RuntimeException('Backfill changed concurrently.');}
            if ($wpdb->update($buildTable, ['is_active' => 0, 'state' => 'rolled_back', 'updated_at' => $this->db->now()], ['build_uuid' => $row['build_uuid'], 'is_active' => 1]) !== 1) {throw new \RuntimeException('Current build could not be fenced.');}
            if ($wpdb->update($buildTable, ['is_active' => 1, 'state' => 'active', 'updated_at' => $this->db->now()], ['build_uuid' => $row['previous_build_uuid'], 'is_active' => 0]) !== 1) {throw new \RuntimeException('Previous build could not be restored.');}
            if ($wpdb->update($this->db->table('backfills'), ['state' => 'rolled_back', 'rolled_back_at' => $this->db->now(), 'row_version' => (int) $locked['row_version'] + 1, 'updated_at' => $this->db->now()], ['id' => (int) $row['id'], 'state' => 'activated', 'row_version' => (int) $locked['row_version']]) !== 1) {throw new \RuntimeException('Rollback state could not be stored.');}
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('smai_backfill_rollback_failed', 'Backfill rollback failed safely.', ['status' => 409]);
        }
        $this->audit->log('backfill_rolled_back', 'backfill', $uuid, 'success', ['restored_build_uuid' => $row['previous_build_uuid']], 'data_rebuild', null, $actorUserId);
        return ['backfill_uuid' => $uuid, 'state' => 'rolled_back', 'active_build_uuid' => $row['previous_build_uuid']];
    }

    /** @return array<string,mixed>|null */
    private function dataset(string $id, string $version): ?array
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('datasets')}` WHERE dataset_id=%s AND dataset_version=%s", $id, $version), ARRAY_A);
        if (!is_array($row)) {return null;}
        $row['definition'] = Json::object((string) $row['definition_json']);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function backfill(string $uuid): ?array
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('backfills')}` WHERE backfill_uuid=%s", $uuid), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<int,mixed> $sources */
    private function sourceCount(array $sources, string $start, string $end): int
    {
        [$where, $args] = $this->sourceWhere($sources, $start, $end, 0);
        if ($where === '') {return 0;}
        return max(0, (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare("SELECT COUNT(*) FROM `{$this->db->table('events')}` WHERE {$where}", ...$args)));
    }

    /** @param array<int,mixed> $sources @return array<int,array<string,mixed>> */
    private function sourceEventsBatch(array $sources, string $start, string $end, int $cursor, int $limit): array
    {
        [$where, $args] = $this->sourceWhere($sources, $start, $end, $cursor);
        if ($where === '') {return [];}
        $args[] = max(1, min(5000, $limit));
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT * FROM `{$this->db->table('events')}` WHERE {$where} ORDER BY id LIMIT %d", ...$args), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<int,mixed> $sources @return array{0:string,1:array<int,mixed>} */
    private function sourceWhere(array $sources, string $start, string $end, int $cursor): array
    {
        $clauses = [];
        $args = [$start, $end, $cursor];
        foreach ($sources as $source) {
            if (!is_array($source)) {continue;}
            $clauses[] = '(event_name=%s AND event_version=%s)';
            $args[] = (string) ($source['event_name'] ?? '');
            $args[] = (string) ($source['event_version'] ?? '');
        }
        return $clauses === [] ? ['', []] : ["occurred_at>=%s AND occurred_at<%s AND id>%d AND (" . implode(' OR ', $clauses) . ')', $args];
    }

    private function buildHash(string $buildUuid): string
    {
        $cursor = 0;
        $context = hash_init('sha256');
        do {
            $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT id,row_hash FROM `{$this->db->table('dataset_rows')}` WHERE build_uuid=%s AND id>%d ORDER BY id LIMIT 5000", $buildUuid, $cursor), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {$cursor = (int) $row['id']; hash_update($context, (string) $row['row_hash'] . "\n");}
        } while (is_array($rows) && count($rows) === 5000);
        return hash_final($context);
    }

    private function normalizeDate(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
