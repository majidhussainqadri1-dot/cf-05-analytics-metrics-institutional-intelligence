<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class FutureActivationService
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|WP_Error */
    public function propose(string $evidenceHash, string $reason, int $actorUserId): array|WP_Error
    {
        $evidenceHash = strtolower(trim($evidenceHash));
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if ($actorUserId < 1 || preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1 || strlen($reason) < 12) {
            return new WP_Error('smai_future_activation_proposal_invalid', 'Future-40 activation proposal requires protected evidence and a meaningful reason.', ['status' => 400]);
        }
        if (!self::protectedEvidenceMatches($evidenceHash)) {
            return new WP_Error('smai_future_activation_evidence_mismatch', 'Future-40 evidence does not match the protected configuration.', ['status' => 409]);
        }
        if (!RuntimeGate::schemaReady()) {
            return new WP_Error('smai_future_schema_gate', 'CF-05 schema must be ready before Future-40 activation can be proposed.', ['status' => 409]);
        }
        $request = [
            'request_uuid' => Uuid::v4(),
            'evidence_hash' => $evidenceHash,
            'reason' => $reason,
            'proposed_by' => $actorUserId,
            'proposed_at' => gmdate('c'),
            'request_hash' => '',
        ];
        $request['request_hash'] = hash('sha256', Json::canonical(array_diff_key($request, ['request_hash' => true])));
        if (!update_option('smai_future40_activation_request', $request, false)) {
            $existing = get_option('smai_future40_activation_request');
            if (!is_array($existing) || !hash_equals((string) ($existing['request_hash'] ?? ''), $request['request_hash'])) {
                return new WP_Error('smai_future_activation_store_failed', 'Future-40 activation proposal could not be stored.', ['status' => 500]);
            }
        }
        if (!(new AuditLogger($this->db))->log('future40_activation_proposed', 'future40_activation', $request['request_uuid'], 'success', [
            'request_hash' => $request['request_hash'],
            'evidence_hash' => $evidenceHash,
        ], 'future40_governance', null, $actorUserId)) {
            delete_option('smai_future40_activation_request');
            return new WP_Error('smai_future_audit_failed', 'Future-40 activation proposal was withdrawn because audit evidence could not be written.', ['status' => 500]);
        }
        return [
            'request_uuid' => $request['request_uuid'],
            'state' => 'proposed',
            'request_hash' => $request['request_hash'],
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public function approve(string $requestHash, int $actorUserId): array|WP_Error
    {
        $requestHash = strtolower(trim($requestHash));
        $request = get_option('smai_future40_activation_request');
        if ($actorUserId < 1 || !is_array($request) || preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1 || !hash_equals((string) ($request['request_hash'] ?? ''), $requestHash)) {
            return new WP_Error('smai_future_activation_request_stale', 'Future-40 activation request is unavailable or stale.', ['status' => 409]);
        }
        if ((int) ($request['proposed_by'] ?? 0) === $actorUserId) {
            return new WP_Error('smai_future_activation_independence', 'Future-40 activation requires an independent approver.', ['status' => 403]);
        }
        $evidence = strtolower((string) ($request['evidence_hash'] ?? ''));
        if (!self::protectedEvidenceMatches($evidence)) {
            return new WP_Error('smai_future_activation_evidence_mismatch', 'Protected Future-40 evidence changed after proposal.', ['status' => 409]);
        }
        if (!RuntimeGate::schemaReady()) {
            return new WP_Error('smai_future_schema_gate', 'CF-05 schema is not ready for Future-40 authorization.', ['status' => 409]);
        }
        update_option('smai_future40_state', 'approved', false);
        update_option('smai_future40_approved', '1', false);
        update_option('smai_future40_evidence_hash', $evidence, false);
        update_option('smai_future40_approved_by', $actorUserId, false);
        update_option('smai_future40_approved_at', gmdate('c'), false);
        delete_option('smai_future40_activation_request');
        if (!(new AuditLogger($this->db))->log('future40_activation_approved', 'future40_activation', (string) ($request['request_uuid'] ?? ''), 'success', [
            'request_hash' => $requestHash,
            'evidence_hash' => $evidence,
            'base_runtime_state' => RuntimeGate::state(),
        ], 'future40_governance', null, $actorUserId)) {
            self::failClosed();
            return new WP_Error('smai_future_audit_failed', 'Future-40 authorization failed closed because audit evidence could not be written.', ['status' => 500]);
        }
        return [
            'state' => 'approved',
            'activation_approved' => self::isApproved(),
            'approved_by' => $actorUserId,
            'base_runtime_state' => RuntimeGate::state(),
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public function disable(string $reason, int $actorUserId): array|WP_Error
    {
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if ($actorUserId < 1 || strlen($reason) < 8) {
            return new WP_Error('smai_future_disable_reason_required', 'A meaningful Future-40 disable reason is required.', ['status' => 400]);
        }
        $previous = (string) get_option('smai_future40_state', 'disabled');
        self::failClosed();
        delete_option('smai_future40_activation_request');
        $audited = (new AuditLogger($this->db))->log('future40_activation_disabled', 'future40_activation', null, 'success', [
            'from' => $previous,
            'to' => 'disabled',
            'reason' => $reason,
        ], 'future40_governance', null, $actorUserId);
        return [
            'previous_state' => $previous,
            'state' => 'disabled',
            'activation_approved' => false,
            'audit_recorded' => $audited,
        ];
    }

    public static function isApproved(): bool
    {
        if ((string) get_option('smai_future40_state', 'disabled') !== 'approved' || get_option('smai_future40_approved', '0') !== '1') {
            return false;
        }
        $stored = strtolower((string) get_option('smai_future40_evidence_hash', ''));
        return self::protectedEvidenceMatches($stored);
    }

    private static function protectedEvidenceMatches(string $evidenceHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1 || !defined('SMAI_FUTURE40_EVIDENCE_SHA256') || !is_string(SMAI_FUTURE40_EVIDENCE_SHA256)) {
            return false;
        }
        $configured = strtolower(trim(SMAI_FUTURE40_EVIDENCE_SHA256));
        return preg_match('/^[a-f0-9]{64}$/', $configured) === 1 && hash_equals($configured, $evidenceHash);
    }

    private static function failClosed(): void
    {
        update_option('smai_future40_state', 'disabled', false);
        update_option('smai_future40_approved', '0', false);
        update_option('smai_future40_evidence_hash', '', false);
        delete_option('smai_future40_approved_by');
        delete_option('smai_future40_approved_at');
    }
}
