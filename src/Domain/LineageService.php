<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;

final class LineageService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function link(
        string $fromType,
        string $fromRef,
        ?string $fromVersion,
        string $toType,
        string $toRef,
        ?string $toVersion,
        string $ownerModule,
        ?string $jobUuid = null,
        ?string $codeSha = null
    ): bool {
        $allowed = ['event','dataset','build','metric','snapshot','report','export','experiment','analysis','decision','provider','restore'];
        if (!in_array($fromType, $allowed, true) || !in_array($toType, $allowed, true)) {
            return false;
        }
        $canonical = [
            'from_type' => $fromType,
            'from_ref' => $fromRef,
            'from_version' => $fromVersion,
            'to_type' => $toType,
            'to_ref' => $toRef,
            'to_version' => $toVersion,
            'owner_module' => $ownerModule,
            'job_uuid' => $jobUuid,
            'code_sha' => $codeSha,
        ];
        $hash = hash('sha256', Json::canonical($canonical));
        $table = $this->db->table('lineage_edges');
        $inserted = $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "INSERT IGNORE INTO `{$table}` (from_type,from_ref,from_version,to_type,to_ref,to_version,code_sha,job_uuid,owner_module,edge_hash,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            $fromType,
            $fromRef,
            $fromVersion,
            $toType,
            $toRef,
            $toVersion,
            $codeSha,
            $jobUuid,
            $ownerModule,
            $hash,
            $this->db->now()
        ));
        return $inserted === 1 || $inserted === 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function upstream(string $type, string $ref, int $limit = 200): array
    {
        $table = $this->db->table('lineage_edges');
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT * FROM `{$table}` WHERE to_type=%s AND to_ref=%s ORDER BY id DESC LIMIT %d",
            $type,
            $ref,
            max(1, min(1000, $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
