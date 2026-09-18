<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;
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

    /**
     * Human-authored or explicitly AI-assisted institutional narrative.
     * Observation, inference and recommendation remain separate fields and the
     * service never performs a clinical, editorial or operational action.
     *
     * @param array<int,array<string,string>> $citations
     */
    public function create(
        string $title,
        string $observation,
        string $inference,
        string $recommendation,
        array $citations,
        bool $aiAssisted,
        int $actorUserId
    ): array|WP_Error {
        $title = Text::truncate(trim(wp_strip_all_tags($title)), 190);
        $observation = Text::truncate(trim(wp_strip_all_tags($observation)), 5000);
        $inference = Text::truncate(trim(wp_strip_all_tags($inference)), 5000);
        $recommendation = Text::truncate(trim(wp_strip_all_tags($recommendation)), 5000);

        if ($actorUserId < 1
            || strlen($title) < 3
            || strlen($observation) < 20
            || strlen($inference) < 8
            || strlen($recommendation) < 8
            || $citations === []
            || count($citations) > 50) {
            return new WP_Error(
                'smai_invalid_narrative',
                'Narrative insight is incomplete.',
                ['status' => 400]
            );
        }

        $detector = new SensitiveValueDetector();
        if ($detector->violations([$title, $observation, $inference, $recommendation, $citations]) !== []) {
            return new WP_Error(
                'smai_invalid_narrative',
                'Narrative insight contains data that is not permitted in analytics narratives.',
                ['status' => 400]
            );
        }

        $normalizedCitations = [];
        $seen = [];
        $metricCatalog = new MetricCatalog($this->db);

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                return new WP_Error(
                    'smai_invalid_narrative_citation',
                    'Narrative citation is invalid.',
                    ['status' => 400]
                );
            }

            $allowedKeys = ['metric_id', 'metric_version', 'snapshot_hash', 'window_start', 'window_end'];
            if (array_diff(array_keys($citation), $allowedKeys) !== []) {
                return new WP_Error(
                    'smai_invalid_narrative_citation',
                    'Narrative citation contains unsupported fields.',
                    ['status' => 400]
                );
            }

            $metricId = trim((string) ($citation['metric_id'] ?? ''));
            $metricVersion = trim((string) ($citation['metric_version'] ?? ''));
            $snapshotHash = strtolower(trim((string) ($citation['snapshot_hash'] ?? '')));
            $windowStart = trim((string) ($citation['window_start'] ?? ''));
            $windowEnd = trim((string) ($citation['window_end'] ?? ''));

            $metric = $metricCatalog->active($metricId, $metricVersion);
            if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', $metricId) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $metricVersion) !== 1
                || !is_array($metric)) {
                return new WP_Error(
                    'smai_invalid_narrative_citation',
                    'Narrative citation does not reference an active metric.',
                    ['status' => 400]
                );
            }

            if ($snapshotHash === '' || strlen($snapshotHash) !== 64 || !ctype_xdigit($snapshotHash)) {
                return new WP_Error(
                    'smai_invalid_narrative_citation',
                    'Narrative snapshot hash is invalid.',
                    ['status' => 400]
                );
            }

            if (($windowStart === '') !== ($windowEnd === '')) {
                return new WP_Error(
                    'smai_invalid_narrative_citation',
                    'Narrative citation window must include both start and end.',
                    ['status' => 400]
                );
            }

            $canonicalStart = '';
            $canonicalEnd = '';
            if ($windowStart !== '') {
                $startTs = $this->strictTimestamp($windowStart); $endTs = $this->strictTimestamp($windowEnd);
                if ($startTs === null || $endTs === null || $startTs >= $endTs) { return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation window is invalid.', ['status' => 400]); }
                $canonicalStart = gmdate('Y-m-d H:i:s', $startTs); $canonicalEnd = gmdate('Y-m-d H:i:s', $endTs);
            }
            $snapshot = $this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT id,window_start,window_end,quality_status,dimensions_json,cohort_size FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND snapshot_hash=%s AND state='published' LIMIT 1",$metricId,$metricVersion,$snapshotHash), ARRAY_A);
            $snapshotDimensions = is_array($snapshot) ? Json::object((string) ($snapshot['dimensions_json'] ?? '{}')) : [];
            $minimum = is_array($metric) ? PrivacyQueryPolicy::effectiveMinimum((array) $metric['definition'], $snapshotDimensions, max((int) $metric['minimum_cohort'], (int) get_option('smai_minimum_cohort', 20))) : PHP_INT_MAX;
            if (!is_array($snapshot)
                || in_array((string) $snapshot['quality_status'], ['suppressed','invalidated'], true)
                || PrivacyQueryPolicy::violations((array) $metric['definition'], $snapshotDimensions) !== []
                || (int) $snapshot['cohort_size'] < $minimum) {
                return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation snapshot is unavailable under the current privacy policy.', ['status' => 409]);
            }
            if ($canonicalStart !== '' && (!hash_equals((string) $snapshot['window_start'], $canonicalStart) || !hash_equals((string) $snapshot['window_end'], $canonicalEnd))) { return new WP_Error('smai_invalid_narrative_citation', 'Narrative citation window does not match the immutable snapshot.', ['status' => 409]); }
            $normalized = ['metric_id'=>$metricId,'metric_version'=>$metricVersion,'snapshot_hash'=>$snapshotHash,'window_start'=>(string)$snapshot['window_start'],'window_end'=>(string)$snapshot['window_end']];

            $citationKey = hash('sha256', Json::canonical($normalized));
            if (isset($seen[$citationKey])) {
                continue;
            }
            $seen[$citationKey] = true;
            $normalizedCitations[] = $normalized;
        }

        if ($normalizedCitations === []) {
            return new WP_Error(
                'smai_invalid_narrative_citation',
                'At least one valid narrative citation is required.',
                ['status' => 400]
            );
        }

        $uuid = Uuid::v4();
        $now = $this->db->now();
        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_narrative_transaction_failed','Narrative transaction could not start.',['status'=>500]);}
        $ok = $wpdb->insert($this->db->table('narratives'), [
            'insight_uuid' => $uuid,
            'state' => 'draft',
            'title' => $title,
            'observation' => $observation,
            'inference' => $inference,
            'recommendation' => $recommendation,
            'citations_json' => Json::canonical($normalizedCitations),
            'ai_assisted' => $aiAssisted ? 1 : 0,
            'author_user_id' => $actorUserId,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($ok !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'smai_narrative_store_failed',
                'Narrative insight could not be stored.',
                ['status' => 500]
            );
        }

        if(!$this->audit->logInOpenTransaction(
            'narrative_insight_created',
            'narrative',
            $uuid,
            'success',
            ['ai_assisted' => $aiAssisted ? 1 : 0, 'citation_count' => count($normalizedCitations)],
            'institutional_reporting',
            null,
            $actorUserId
        )) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_narrative_audit_failed','Narrative was not committed because audit evidence failed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_narrative_commit_failed','Narrative could not be committed.',['status'=>500]);}

        return [
            'insight_uuid' => $uuid,
            'state' => 'draft',
            'row_version' => 1,
            'ai_assisted' => $aiAssisted,
            'citation_count' => count($normalizedCitations),
        ];
    }

    public function publish(string $uuid, int $expectedVersion, int $reviewerUserId): array|WP_Error
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1 || $expectedVersion < 1 || $reviewerUserId < 1) {
            return new WP_Error('smai_invalid_narrative_publish', 'Narrative publication request is invalid.', ['status' => 400]);
        }

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
        if (!$this->citationsAvailable(Json::list((string) $row['citations_json']))) { return new WP_Error('smai_narrative_citation_stale', 'Narrative publication is blocked because cited evidence is no longer publishable.', ['status' => 409]); }

        $wpdb=$this->db->wpdb();
        if($wpdb->query('START TRANSACTION')===false){return new WP_Error('smai_narrative_transaction_failed','Narrative publication transaction could not start.',['status'=>500]);}
        $updated = $wpdb->update($table, [
            'state' => 'published',
            'reviewer_user_id' => $reviewerUserId,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], [
            'id' => (int) $row['id'],
            'state' => 'draft',
            'row_version' => $expectedVersion,
        ]);

        if ($updated !== 1) { $wpdb->query('ROLLBACK');
            return new WP_Error('smai_narrative_conflict', 'Narrative changed concurrently.', ['status' => 409]);
        }

        if(!$this->audit->logInOpenTransaction(
            'narrative_insight_published',
            'narrative',
            $uuid,
            'success',
            ['ai_assisted' => (int) $row['ai_assisted']],
            'institutional_reporting',
            null,
            $reviewerUserId
        )) { $wpdb->query('ROLLBACK'); return new WP_Error('smai_narrative_audit_failed','Narrative publication was not committed because audit evidence failed.',['status'=>503]); }
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('smai_narrative_commit_failed','Narrative publication could not be committed.',['status'=>500]);}

        return [
            'insight_uuid' => $uuid,
            'state' => 'published',
            'row_version' => $expectedVersion + 1,
        ];
    }
    /** @param array<int,mixed> $citations */
    private function citationsAvailable(array $citations): bool
    {
        if ($citations === []) { return false; }
        foreach ($citations as $citation) {
            if (!is_array($citation)) { return false; }
            $metricId=(string)($citation['metric_id']??''); $metricVersion=(string)($citation['metric_version']??''); $hash=strtolower((string)($citation['snapshot_hash']??''));
            $metric=(new MetricCatalog($this->db))->active($metricId,$metricVersion);
            if (!is_array($metric) || preg_match('/^[a-f0-9]{64}$/',$hash)!==1) { return false; }
            $snapshot=$this->db->wpdb()->get_row($this->db->wpdb()->prepare("SELECT window_start,window_end,quality_status,dimensions_json,cohort_size FROM `{$this->db->table('metric_snapshots')}` WHERE metric_id=%s AND metric_version=%s AND snapshot_hash=%s AND state='published' LIMIT 1",$metricId,$metricVersion,$hash),ARRAY_A);
            if (!is_array($snapshot)) { return false; }
            $dimensions=Json::object((string)($snapshot['dimensions_json']??'{}'));
            $minimum=PrivacyQueryPolicy::effectiveMinimum((array)$metric['definition'],$dimensions,max((int)$metric['minimum_cohort'],(int)get_option('smai_minimum_cohort',20)));
            if (in_array((string)$snapshot['quality_status'],['suppressed','invalidated'],true)
                || PrivacyQueryPolicy::violations((array)$metric['definition'],$dimensions)!==[]
                || (int)$snapshot['cohort_size']<$minimum
                || !hash_equals((string)$snapshot['window_start'],(string)($citation['window_start']??''))
                || !hash_equals((string)$snapshot['window_end'],(string)($citation['window_end']??''))) { return false; }
        }
        return true;
    }
    private function strictTimestamp(string $value): ?int
    {
        if (strlen($value) > 35 || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/',$value,$m)!==1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) { return null; }
        $t=strtotime($value); return $t===false?null:$t;
    }

}
