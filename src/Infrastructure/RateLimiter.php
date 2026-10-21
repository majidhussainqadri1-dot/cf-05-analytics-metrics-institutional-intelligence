<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class RateLimiter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function consume(string $scope, string $subject, int $limit, int $windowSeconds): bool
    {
        $limit = max(1, min(100000, $limit));
        $windowSeconds = max(1, min(DAY_IN_SECONDS, $windowSeconds));
        $bucketStart = (int) (floor(time() / $windowSeconds) * $windowSeconds);
        $bucket = gmdate('Y-m-d H:i:s', $bucketStart);
        $expires = gmdate('Y-m-d H:i:s', $bucketStart + $windowSeconds + 60);
        $key = hash('sha256', $scope . '|' . $subject);
        $table = $this->db->table('rate_limits');
        $sql = $this->db->wpdb()->prepare(
            "INSERT INTO `{$table}` (limit_key,bucket_start,count_value,expires_at) VALUES (%s,%s,1,%s)
             ON DUPLICATE KEY UPDATE count_value=IF(count_value<=%d,count_value+1,count_value),expires_at=VALUES(expires_at)",
            $key,
            $bucket,
            $expires,
            $limit
        );
        $result = $this->db->wpdb()->query($sql);
        if ($result === false) {
            return false;
        }
        $rawCount = $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
            "SELECT count_value FROM `{$table}` WHERE limit_key=%s AND bucket_start=%s",
            $key,
            $bucket
        ));
        // A failed/empty SQL read must NEVER become numeric zero (allow).
        // The inserted counter is at least one; anything else is unavailable.
        if ((!is_int($rawCount) && !is_string($rawCount))
            || preg_match('/^[1-9][0-9]*$/D', (string) $rawCount) !== 1
            || strlen((string) $rawCount) > 12) {
            return false;
        }
        $count = (int) $rawCount;
        return $count >= 1 && $count <= $limit;
    }
}
