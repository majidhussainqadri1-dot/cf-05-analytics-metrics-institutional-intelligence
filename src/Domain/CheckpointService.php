<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\SensitiveValueDetector;

final class CheckpointService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed>|null */
    public function get(string $streamRef, string $consumerRef): ?array
    {
        if (!$this->validRef($streamRef) || !$this->validRef($consumerRef)) {
            return null;
        }
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('checkpoints')}` WHERE stream_ref=%s AND consumer_ref=%s",
            $streamRef,
            $consumerRef
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $context */
    public function advance(string $streamRef, string $consumerRef, string $contractVersion, ?string $watermarkAt, ?int $sequence, array $context = []): bool
    {
        if (!$this->validRef($streamRef)
            || !$this->validRef($consumerRef)
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $contractVersion) !== 1
            || ($sequence !== null && $sequence < 1)
            || count($context) > 20
            || (new SensitiveValueDetector())->violations($context) !== []) {
            return false;
        }
        $watermark = null;
        if ($watermarkAt !== null) {
            $timestamp = strtotime($watermarkAt);
            if ($timestamp === false) {
                return false;
            }
            $watermark = gmdate('Y-m-d H:i:s', $timestamp);
        }

        $wpdb = $this->db->wpdb();
        $table = $this->db->table('checkpoints');
        $lockName = substr($wpdb->prefix . 'smai_checkpoint_' . hash('sha256', $streamRef . '|' . $consumerRef), 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 3)) !== 1) {
            return false;
        }
        try {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE stream_ref=%s AND consumer_ref=%s",
                $streamRef,
                $consumerRef
            ), ARRAY_A);
            $maxSequence = max((int) ($current['source_sequence'] ?? 0), (int) ($sequence ?? 0));
            if (is_array($current) && !empty($current['watermark_at']) && ($watermark === null || (string) $current['watermark_at'] > $watermark)) {
                $watermark = (string) $current['watermark_at'];
            }
            $canonical = [
                'stream_ref' => $streamRef,
                'consumer_ref' => $consumerRef,
                'contract_version' => $contractVersion,
                'watermark_at' => $watermark,
                'source_sequence' => $maxSequence,
                'context' => $context,
            ];
            $data = [
                'contract_version' => $contractVersion,
                'watermark_at' => $watermark,
                'source_sequence' => $maxSequence ?: null,
                'checkpoint_json' => Json::canonical($context),
                'checkpoint_hash' => hash('sha256', Json::canonical($canonical)),
                'updated_at' => $this->db->now(),
            ];
            if (is_array($current)) {
                $updated = $wpdb->update($table, $data, ['id' => (int) $current['id'], 'checkpoint_hash' => (string) $current['checkpoint_hash']]);
                if ($updated === 0 && hash_equals((string) $current['checkpoint_hash'], (string) $data['checkpoint_hash'])) {
                    return true;
                }
                return $updated === 1;
            }
            $data['stream_ref'] = $streamRef;
            $data['consumer_ref'] = $consumerRef;
            return $wpdb->insert($table, $data) === 1;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private function validRef(string $value): bool
    {
        return strlen($value) >= 3 && strlen($value) <= 190 && preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1;
    }
}
