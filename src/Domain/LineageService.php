<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;

final class LineageService
{
    private const TYPES = ['event','dataset','build','metric','snapshot','report','export','experiment','analysis','decision','provider','restore'];

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
        if (!in_array($fromType, self::TYPES, true)
            || !in_array($toType, self::TYPES, true)
            || !$this->validRef($fromRef, 190)
            || !$this->validRef($toRef, 190)
            || preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $ownerModule) !== 1
            || !$this->validOptionalVersion($fromVersion)
            || !$this->validOptionalVersion($toVersion)
            || ($jobUuid !== null && preg_match('/^[0-9a-f-]{36}$/i', $jobUuid) !== 1)
            || ($codeSha !== null && preg_match('/^[a-f0-9]{40,64}$/', $codeSha) !== 1)) {
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
        if ($inserted === 1) {
            return true;
        }
        if ($inserted === 0) {
            $existing = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
                "SELECT edge_hash FROM `{$table}` WHERE edge_hash=%s",
                $hash
            ));
            return is_string($existing) && hash_equals($existing, $hash);
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public function upstream(string $type, string $ref, int $limit = 200): array
    {
        if (!in_array($type, self::TYPES, true) || !$this->validRef($ref, 190)) {
            return [];
        }
        $rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('lineage_edges')}` WHERE to_type=%s AND to_ref=%s ORDER BY id DESC LIMIT %d",
            $type,
            $ref,
            max(1, min(1000, $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function validRef(string $value, int $maximum): bool
    {
        return $value !== '' && strlen($value) <= $maximum && preg_match('/^[A-Za-z0-9_.:@|\/-]+$/', $value) === 1;
    }

    private function validOptionalVersion(?string $version): bool
    {
        return $version === null || ($version !== '' && strlen($version) <= 64 && preg_match('/^[A-Za-z0-9_.:+-]+$/', $version) === 1);
    }
}
