<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use Sabri\AnalyticsIntelligence\Infrastructure\Uuid;
use WP_Error;

final class NarrativeService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<int,array<string,string>> $citations */
    public function create(string $title, string $observation, string $inference, string $recommendation, array $citations, bool $aiAssisted, int $actorUserId): array|WP_Error
    {
        if (strlen(trim($title)) < 3 || strlen(trim($observation)) < 20 || $citations === [] || count($citations) > 50) {
            return new WP_Error('smai_invalid_narrative', 'Narrative insight is incomplete.', ['status' => 400]);
        }
        foreach ($citations as $citation) {
            if (!is_array($citation)
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($citation['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($citation['metric_version'] ?? '')) !== 1
                || (new MetricCatalog($this->db))->active((string) $citation['metric_id'], (string) $citation['metric_version']) === null) {
                return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation does not reference an active metric.', ['status' => 400]);
            }
        }
        $uuid = Uuid::v4();
        $now = $this->db->now();
        $ok = $this->db->wpdb()->insert($this->db->table('narratives'), [
            'insight_uuid' => $uuid,
            'state' => 'draft',
            'title' => Text::truncate(wp_strip_all_tags($title), 190),
            'observation' => Text::truncate(wp_strip_all_tags($observation), 5000),
            'inference' => Text::truncate(wp_strip_all_tags($inference), 5000),
            'recommendation' => Text::truncate(wp_strip_all_tags($recommendation), 5000),
            'citations_json' => Json::canonical($citations),
            'ai_assisted' => $aiAssisted ? 1 : 0,
            'author_user_id' => $actorUserId,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok === 1
            ? ['insight_uuid' => $uuid, 'state' => 'draft', 'row_version' => 1]
            : new WP_Error('smai_narrative_store_failed', 'Narrative insight could not be stored.', ['status' => 500]);
    }

    public function publish(string $uuid, int $expectedVersion, int $reviewerUserId): array|WP_Error
    {
        $table = $this->db->table('narratives');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE insight_uuid=%s",
            $uuid
        ), ARRAY_A);
        if (!is_array($row) || (string) $row['state'] !== 'draft' || (int) $row['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_narrative_stale', 'Narrative is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $row['author_user_id'] === $reviewerUserId) {
            return new WP_Error('smai_separation_of_duties', 'Independent narrative review is required.', ['status' => 403]);
        }
        $updated = $this->db->wpdb()->update($table, [
            'state' => 'published',
            'reviewer_user_id' => $reviewerUserId,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $row['id'], 'state' => 'draft', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_narrative_conflict', 'Narrative changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('narrative_insight_published', 'narrative', $uuid, 'success', ['ai_assisted' => (int) $row['ai_assisted']], 'institutional_reporting', null, $reviewerUserId);
        return ['insight_uuid' => $uuid, 'state' => 'published', 'row_version' => $expectedVersion + 1];
    }
}
