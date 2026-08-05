<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;

final class PipelineService
{
    private Database $db;
    private TransformationEngine $transform;
    private LineageService $lineage;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->transform = new TransformationEngine();
        $this->lineage = new LineageService($db);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function runJob(array $payload): array
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        $event = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('events')}` WHERE event_id=%s",
            $eventId
        ), ARRAY_A);
        if (!is_array($event)) {
            throw new \RuntimeException('Analytics event is unavailable.');
        }
        $event['properties'] = Json::object((string) $event['properties_json']);
        $datasets = $this->db->wpdb()->get_results(
            "SELECT * FROM `{$this->db->table('datasets')}` WHERE state='published' ORDER BY id",
            ARRAY_A
        );
        $projected = 0;
        foreach (is_array($datasets) ? $datasets : [] as $dataset) {
            $definition = Json::object((string) $dataset['definition_json']);
            if (!$this->matchesSource($event, (array) ($definition['sources'] ?? []))) {
                continue;
            }
            $build = $this->activeBuild((string) $dataset['dataset_id'], (string) $dataset['dataset_version'], (int) $dataset['created_by']);
            if (!empty($event['correction_of_event_id'])) {
                $this->db->wpdb()->query($this->db->wpdb()->prepare(
                    "UPDATE `{$this->db->table('dataset_rows')}` SET is_current=0,effective_to=%s WHERE build_uuid=%s AND source_event_id=%s AND is_current=1",
                    $event['occurred_at'],
                    $build['build_uuid'],
                    $event['correction_of_event_id']
                ));
            }
            $row = $this->transform->map($event, (array) ($definition['fields'] ?? []));
            $rowJson = Json::canonical($row);
            $rowHash = hash('sha256', $eventId . '|' . $rowJson);
            $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
                "INSERT IGNORE INTO `{$this->db->table('dataset_rows')}` (build_uuid,dataset_id,dataset_version,source_event_id,source_object_ref,deletion_key,effective_from,effective_to,is_current,row_json,row_hash,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,NULL,1,%s,%s,%s)",
                $build['build_uuid'],
                $dataset['dataset_id'],
                $dataset['dataset_version'],
                $eventId,
                $event['object_ref'],
                $event['deletion_key'],
                $event['occurred_at'],
                $rowJson,
                $rowHash,
                $this->db->now()
            ));
            if ($inserted === 1) {
                $projected++;
                $this->db->wpdb()->query($this->db->wpdb()->prepare(
                    "UPDATE `{$this->db->table('dataset_builds')}` SET row_count=row_count+1,updated_at=%s WHERE build_uuid=%s",
                    $this->db->now(),
                    $build['build_uuid']
                ));
            }
            $this->lineage->link('event', $eventId, (string) $event['event_version'], 'build', (string) $build['build_uuid'], (string) $dataset['dataset_version'], (string) $dataset['owner_module'], (string) ($payload['job_uuid'] ?? null), defined('SMAI_CODE_SHA') ? SMAI_CODE_SHA : null);
        }
        $this->db->wpdb()->update($this->db->table('events'), ['processed_at' => $this->db->now()], ['event_id' => $eventId]);
        (new CheckpointService($this->db))->advance(
            (string) $event['source_module'] . ':' . (string) $event['source_environment'],
            'cf05-pipeline',
            SMAI_CONTRACT_VERSION,
            (string) $event['occurred_at'],
            $event['source_sequence'] === null ? null : (int) $event['source_sequence'],
            ['event_id' => $eventId]
        );
        return ['event_id' => $eventId, 'projected_datasets' => $projected];
    }

    /** @param array<string,mixed> $event @param array<int,mixed> $sources */
    private function matchesSource(array $event, array $sources): bool
    {
        foreach ($sources as $source) {
            if (is_array($source)
                && hash_equals((string) ($source['event_name'] ?? ''), (string) $event['event_name'])
                && hash_equals((string) ($source['event_version'] ?? ''), (string) $event['event_version'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function activeBuild(string $datasetId, string $version, int $creator): array
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('dataset_builds');
        $datasetTable = $this->db->table('datasets');
        $wpdb->query('START TRANSACTION');
        try {
            $lock = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$datasetTable}` WHERE dataset_id=%s AND dataset_version=%s FOR UPDATE",
                $datasetId,
                $version
            ));
            if (!$lock) {
                throw new \RuntimeException('Dataset disappeared while creating an active build.');
            }
            $build = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE dataset_id=%s AND dataset_version=%s AND is_active=1 LIMIT 1 FOR UPDATE",
                $datasetId,
                $version
            ), ARRAY_A);
            if (is_array($build)) {
                $wpdb->query('COMMIT');
                return $build;
            }
            $uuid = Uuid::v4();
            $now = $this->db->now();
            if ($wpdb->insert($table, [
                'build_uuid' => $uuid,
                'dataset_id' => $datasetId,
                'dataset_version' => $version,
                'state' => 'active',
                'is_active' => 1,
                'row_count' => 0,
                'created_by' => $creator,
                'created_at' => $now,
                'activated_at' => $now,
                'updated_at' => $now,
            ]) !== 1) {
                throw new \RuntimeException('Active dataset build could not be created.');
            }
            $wpdb->query('COMMIT');
            return ['build_uuid' => $uuid, 'dataset_id' => $datasetId, 'dataset_version' => $version, 'state' => 'active', 'is_active' => 1];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

}
