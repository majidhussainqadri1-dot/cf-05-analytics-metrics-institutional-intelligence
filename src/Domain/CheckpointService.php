<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;

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
        $table = $this->db->table('checkpoints');
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE stream_ref=%s AND consumer_ref=%s",
            $streamRef,
            $consumerRef
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $context */
    public function advance(string $streamRef, string $consumerRef, string $contractVersion, ?string $watermarkAt, ?int $sequence, array $context = []): bool
    {
        $table = $this->db->table('checkpoints');
        $current = $this->get($streamRef, $consumerRef);
        $maxSequence = max((int) ($current['source_sequence'] ?? 0), (int) ($sequence ?? 0));
        $watermark = $watermarkAt;
        if (is_array($current) && !empty($current['watermark_at']) && ($watermark === null || $current['watermark_at'] > $watermark)) {
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
            return $this->db->wpdb()->update($table, $data, ['id' => (int) $current['id']]) === 1;
        }
        $data['stream_ref'] = $streamRef;
        $data['consumer_ref'] = $consumerRef;
        return $this->db->wpdb()->insert($table, $data) === 1;
    }
}
