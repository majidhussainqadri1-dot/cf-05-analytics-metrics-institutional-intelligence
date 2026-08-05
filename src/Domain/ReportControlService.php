<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

use Sabri\AnalyticsIntelligence\Infrastructure\AuditLogger;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\Json;
use Sabri\AnalyticsIntelligence\Infrastructure\Text;
use WP_Error;

final class ReportControlService
{
    private Database $db;
    private AuditLogger $audit;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->audit = new AuditLogger($db);
    }

    /** @param array<string,mixed> $changes */
    public function update(string $uuid, int $expectedVersion, array $changes, int $actorUserId): array|WP_Error
    {
        $report = $this->get($uuid);
        if (!is_array($report) || !in_array((string) $report['state'], ['draft', 'paused'], true)) {
            return new WP_Error('smai_report_not_editable', 'Only draft or paused reports can be updated.', ['status' => 409]);
        }
        if ((int) $report['owner_user_id'] !== $actorUserId || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_update_denied', 'Report is unavailable, stale or not owned by the current user.', ['status' => 403]);
        }

        $definition = Json::object((string) $report['definition_json']);
        if (array_key_exists('recipients', $changes)) {
            $recipients = is_array($changes['recipients']) ? array_values($changes['recipients']) : [];
            $valid = $this->validateRecipients($recipients);
            if (is_wp_error($valid)) {
                return $valid;
            }
            $definition['recipients'] = $recipients;
        }
        if (array_key_exists('metrics', $changes)) {
            $metrics = is_array($changes['metrics']) ? array_values($changes['metrics']) : [];
            $valid = $this->validateMetrics($metrics, (string) $report['project_uuid'], $actorUserId);
            if (is_wp_error($valid)) {
                return $valid;
            }
            $definition['metrics'] = $metrics;
        }

        $name = array_key_exists('name', $changes)
            ? Text::truncate(trim(wp_strip_all_tags((string) $changes['name'])), 190)
            : (string) $report['name'];
        if (strlen($name) < 3) {
            return new WP_Error('smai_invalid_report_name', 'Report name is invalid.', ['status' => 400]);
        }

        $schedule = array_key_exists('schedule', $changes) ? $changes['schedule'] : $report['schedule_rrule'];
        if ($schedule !== null && !in_array((string) $schedule, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            return new WP_Error('smai_invalid_report_schedule', 'Report schedule is invalid.', ['status' => 400]);
        }

        $expiresAt = $report['expires_at'];
        if (array_key_exists('expires_at', $changes)) {
            if ($changes['expires_at'] === null || $changes['expires_at'] === '') {
                $expiresAt = null;
            } else {
                $timestamp = strtotime((string) $changes['expires_at']);
                $project = (new AccessProjectService($this->db))->get((string) $report['project_uuid']);
                if ($timestamp === false || $timestamp <= time() || !is_array($project) || $timestamp > strtotime((string) $project['expires_at'])) {
                    return new WP_Error('smai_invalid_report_expiry', 'Report expiry is invalid or exceeds project expiry.', ['status' => 400]);
                }
                $expiresAt = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        $updated = $this->db->wpdb()->update($this->db->table('reports'), [
            'name' => $name,
            'definition_json' => Json::canonical($definition),
            'schedule_rrule' => $schedule,
            'next_run_at' => null,
            'expires_at' => $expiresAt,
            'state' => 'draft',
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'row_version' => $expectedVersion, 'state' => (string) $report['state']]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_update_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('report_updated', 'report', $uuid, 'success', ['requires_reapproval' => true], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => 'draft', 'row_version' => $expectedVersion + 1, 'requires_reapproval' => true];
    }

    public function pause(string $uuid, int $expectedVersion, string $reason, int $actorUserId): array|WP_Error
    {
        return $this->transition($uuid, $expectedVersion, 'active', 'paused', $reason, $actorUserId, false);
    }

    public function resume(string $uuid, int $expectedVersion, string $reason, int $actorUserId): array|WP_Error
    {
        $report = $this->get($uuid);
        if (!is_array($report) || (string) $report['state'] !== 'paused' || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_resume_stale', 'Report is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $report['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'Report owner cannot independently resume their own report.', ['status' => 403]);
        }
        $project = (new AccessProjectService($this->db))->get((string) $report['project_uuid']);
        if (!is_array($project) || (string) $project['state'] !== 'active' || strtotime((string) $project['expires_at']) <= time()) {
            return new WP_Error('smai_report_project_inactive', 'Report access project is inactive.', ['status' => 409]);
        }
        $reason = $this->reason($reason);
        if (is_wp_error($reason)) {
            return $reason;
        }
        $schedule = $report['schedule_rrule'] === null ? null : (string) $report['schedule_rrule'];
        $next = $schedule === null ? null : gmdate('Y-m-d H:i:s', $this->nextTimestamp($schedule, time()));
        $updated = $this->db->wpdb()->update($this->db->table('reports'), [
            'state' => 'active',
            'next_run_at' => $next,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'state' => 'paused', 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_resume_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('report_resumed', 'report', $uuid, 'success', ['reason' => $reason], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => 'active', 'row_version' => $expectedVersion + 1, 'next_run_at' => $next];
    }

    public function revoke(string $uuid, int $expectedVersion, string $reason, int $actorUserId): array|WP_Error
    {
        $report = $this->get($uuid);
        if (!is_array($report) || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_revoke_stale', 'Report is unavailable or stale.', ['status' => 409]);
        }
        if ((string) $report['state'] === 'revoked') {
            return ['report_uuid' => $uuid, 'state' => 'revoked', 'row_version' => $expectedVersion, 'unchanged' => true];
        }
        if ((int) $report['owner_user_id'] !== $actorUserId && !user_can($actorUserId, 'smai_manage_access')) {
            return new WP_Error('smai_report_revoke_denied', 'You are not authorized to revoke this report.', ['status' => 403]);
        }
        $reason = $this->reason($reason);
        if (is_wp_error($reason)) {
            return $reason;
        }
        $now = $this->db->now();
        $updated = $this->db->wpdb()->update($this->db->table('reports'), [
            'state' => 'revoked',
            'next_run_at' => null,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $now,
        ], ['id' => (int) $report['id'], 'row_version' => $expectedVersion, 'state' => (string) $report['state']]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_revoke_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        $this->revokeDeliveries($uuid, null, $now);
        $this->audit->log('report_revoked', 'report', $uuid, 'success', ['reason' => $reason], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => 'revoked', 'row_version' => $expectedVersion + 1, 'unchanged' => false];
    }

    public function unsubscribe(string $uuid, int $expectedVersion, int $actorUserId): array|WP_Error
    {
        $report = $this->get($uuid);
        if (!is_array($report) || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_unsubscribe_stale', 'Report is unavailable or stale.', ['status' => 409]);
        }
        $definition = Json::object((string) $report['definition_json']);
        $recipients = is_array($definition['recipients'] ?? null) ? array_values($definition['recipients']) : [];
        $remaining = [];
        $removed = false;
        foreach ($recipients as $recipient) {
            if (is_array($recipient) && (string) ($recipient['type'] ?? '') === 'user' && (int) ($recipient['user_id'] ?? 0) === $actorUserId) {
                $removed = true;
                continue;
            }
            $remaining[] = $recipient;
        }
        if (!$removed) {
            return new WP_Error('smai_report_not_subscribed', 'Current user is not subscribed to this report.', ['status' => 404]);
        }
        $definition['recipients'] = $remaining;
        $newState = $remaining === [] && (string) $report['state'] === 'active' ? 'paused' : (string) $report['state'];
        $updated = $this->db->wpdb()->update($this->db->table('reports'), [
            'definition_json' => Json::canonical($definition),
            'state' => $newState,
            'next_run_at' => $newState === 'paused' ? null : $report['next_run_at'],
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_unsubscribe_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        $now = $this->db->now();
        $this->revokeDeliveries($uuid, hash('sha256', 'user:' . $actorUserId), $now);
        $this->audit->log('report_unsubscribed', 'report', $uuid, 'success', ['recipient_user_id' => $actorUserId], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => $newState, 'row_version' => $expectedVersion + 1, 'subscribed' => false];
    }

    private function transition(string $uuid, int $expectedVersion, string $from, string $to, string $reason, int $actorUserId, bool $independent): array|WP_Error
    {
        $report = $this->get($uuid);
        if (!is_array($report) || (string) $report['state'] !== $from || (int) $report['row_version'] !== $expectedVersion) {
            return new WP_Error('smai_report_transition_stale', 'Report is unavailable or stale.', ['status' => 409]);
        }
        if ((int) $report['owner_user_id'] !== $actorUserId && !user_can($actorUserId, 'smai_manage_reports')) {
            return new WP_Error('smai_report_transition_denied', 'You are not authorized to change this report.', ['status' => 403]);
        }
        if ($independent && (int) $report['owner_user_id'] === $actorUserId) {
            return new WP_Error('smai_separation_of_duties', 'An independent actor is required.', ['status' => 403]);
        }
        $reason = $this->reason($reason);
        if (is_wp_error($reason)) {
            return $reason;
        }
        $updated = $this->db->wpdb()->update($this->db->table('reports'), [
            'state' => $to,
            'next_run_at' => null,
            'row_version' => $expectedVersion + 1,
            'updated_at' => $this->db->now(),
        ], ['id' => (int) $report['id'], 'state' => $from, 'row_version' => $expectedVersion]);
        if ($updated !== 1) {
            return new WP_Error('smai_report_transition_conflict', 'Report changed concurrently.', ['status' => 409]);
        }
        $this->audit->log('report_' . $to, 'report', $uuid, 'success', ['reason' => $reason], 'institutional_reporting', null, $actorUserId);
        return ['report_uuid' => $uuid, 'state' => $to, 'row_version' => $expectedVersion + 1];
    }

    /** @param array<int,mixed> $recipients */
    private function validateRecipients(array $recipients): bool|WP_Error
    {
        if ($recipients === [] || count($recipients) > 50) {
            return new WP_Error('smai_invalid_report_recipient', 'Report recipients are invalid.', ['status' => 400]);
        }
        $seen = [];
        foreach ($recipients as $recipient) {
            $id = is_array($recipient) ? (int) ($recipient['user_id'] ?? 0) : 0;
            if (!is_array($recipient) || (string) ($recipient['type'] ?? '') !== 'user' || $id < 1 || !user_can($id, 'smai_view_insights') || isset($seen[$id])) {
                return new WP_Error('smai_invalid_report_recipient', 'Report recipient is invalid or duplicated.', ['status' => 400]);
            }
            $seen[$id] = true;
        }
        return true;
    }

    /** @param array<int,mixed> $metrics */
    private function validateMetrics(array $metrics, string $projectUuid, int $actorUserId): bool|WP_Error
    {
        if ($metrics === [] || count($metrics) > 50) {
            return new WP_Error('smai_invalid_report_metric', 'Report metrics are invalid.', ['status' => 400]);
        }
        $access = new AccessProjectService($this->db);
        $seen = [];
        foreach ($metrics as $metric) {
            if (!is_array($metric)
                || preg_match('/^[a-z][a-z0-9_.-]{2,189}$/', (string) ($metric['metric_id'] ?? '')) !== 1
                || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($metric['metric_version'] ?? '')) !== 1
                || !is_array($metric['dimensions'] ?? [])) {
                return new WP_Error('smai_invalid_report_metric', 'Report metric is invalid.', ['status' => 400]);
            }
            $dimensions = $metric['dimensions'];
            ksort($dimensions);
            $ref = 'metric:' . $metric['metric_id'] . '@' . $metric['metric_version'];
            if (!$access->authorize($projectUuid, $actorUserId, $ref, [])) {
                return new WP_Error('smai_report_access_denied', 'Access project does not authorize a report metric.', ['status' => 403]);
            }
            $key = $ref . '|' . hash('sha256', Json::canonical($dimensions));
            if (isset($seen[$key])) {
                return new WP_Error('smai_duplicate_report_metric', 'Report contains a duplicate metric slice.', ['status' => 400]);
            }
            $seen[$key] = true;
        }
        return true;
    }

    private function revokeDeliveries(string $uuid, ?string $recipientHash, string $now): void
    {
        $where = 'report_uuid=%s AND revoked_at IS NULL';
        $args = [$now, $now, $now, $uuid];
        if ($recipientHash !== null) {
            $where .= ' AND recipient_hash=%s';
            $args[] = $recipientHash;
        }
        $this->db->wpdb()->query($this->db->wpdb()->prepare(
            "UPDATE `{$this->db->table('report_deliveries')}` SET state='revoked',revoked_at=%s,expires_at=%s,token_hash=NULL,updated_at=%s WHERE {$where}",
            ...$args
        ));
    }

    private function get(string $uuid): ?array
    {
        $row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare(
            "SELECT * FROM `{$this->db->table('reports')}` WHERE report_uuid=%s",
            $uuid
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function reason(string $reason): string|WP_Error
    {
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        return strlen($reason) >= 8 ? $reason : new WP_Error('smai_reason_required', 'A meaningful reason is required.', ['status' => 400]);
    }

    private function nextTimestamp(string $schedule, int $from): int
    {
        return match ($schedule) {
            'hourly' => $from + HOUR_IN_SECONDS,
            'daily' => $from + DAY_IN_SECONDS,
            'weekly' => $from + WEEK_IN_SECONDS,
            'monthly' => strtotime('+1 month', $from) ?: $from + 30 * DAY_IN_SECONDS,
            default => $from + DAY_IN_SECONDS,
        };
    }
}
