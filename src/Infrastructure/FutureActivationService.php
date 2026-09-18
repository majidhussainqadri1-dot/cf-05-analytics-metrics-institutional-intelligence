<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use WP_Error;

final class FutureActivationService
{
    private const OPTION_REQUEST = 'smai_future40_activation_request';
    private const OPTION_STATE = 'smai_future40_state';
    private const OPTION_APPROVED = 'smai_future40_approved';
    private const OPTION_EVIDENCE = 'smai_future40_evidence_hash';
    private const OPTION_APPROVED_BY = 'smai_future40_approved_by';
    private const OPTION_APPROVED_AT = 'smai_future40_approved_at';

    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|WP_Error */
    public function propose(string $evidenceHash, string $reason, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_manage_future_intelligence')) {
            return new WP_Error('smai_future_activation_forbidden', 'Future-40 activation proposal is not authorized.', ['status'=>403]);
        }
        $evidenceHash = strtolower(trim($evidenceHash));
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if ($actorUserId < 1 || preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1 || strlen($reason) < 12
            || (new SensitiveValueDetector())->violations($reason) !== []) {
            return new WP_Error('smai_future_activation_proposal_invalid', 'Future-40 activation proposal requires protected evidence and a meaningful reason.', ['status' => 400]);
        }
        if (!self::protectedEvidenceMatches($evidenceHash)) {
            return new WP_Error('smai_future_activation_evidence_mismatch', 'Future-40 evidence does not match the protected configuration.', ['status' => 409]);
        }
        if (!RuntimeGate::schemaReady()) {
            return new WP_Error('smai_future_schema_gate', 'CF-05 schema must be ready before Future-40 activation can be proposed.', ['status' => 409]);
        }
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future-40 activation transaction could not start.', ['status'=>500]);
        try {
            if ($this->readOptionForUpdate(self::OPTION_REQUEST) !== null) {
                return $this->rollbackError(new WP_Error('smai_future_activation_request_pending', 'A Future-40 activation proposal is already pending review.', ['status'=>409]));
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
            if (!$this->writeOption(self::OPTION_REQUEST, $request, true)) {
                return $this->rollbackError(new WP_Error('smai_future_activation_request_pending', 'A Future-40 activation proposal is already pending review.', ['status'=>409]));
            }
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future40_activation_proposed', 'future40_activation', $request['request_uuid'], 'success', [
                'request_hash' => $request['request_hash'],
                'evidence_hash' => $evidenceHash,
            ], 'future40_governance', null, $actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Future-40 activation proposal was not committed because audit evidence could not be written.', ['status' => 500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future-40 activation proposal could not be committed.', ['status'=>500]);
            $this->flushOptionCaches([self::OPTION_REQUEST]);
            return ['request_uuid'=>$request['request_uuid'],'state'=>'proposed','request_hash'=>$request['request_hash']];
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_activation_store_failed', 'Future-40 activation proposal failed safely.', ['status'=>500]);
        }
    }

    /** @return array<string,mixed>|WP_Error */
    public function approve(string $requestHash, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_approve_future_intelligence')) {
            return new WP_Error('smai_future_activation_forbidden', 'Future-40 activation approval is not authorized.', ['status'=>403]);
        }
        $requestHash = strtolower(trim($requestHash));
        if (preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1) {
            return new WP_Error('smai_future_activation_request_stale', 'Future-40 activation request is unavailable or stale.', ['status'=>409]);
        }
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future-40 activation transaction could not start.', ['status'=>500]);
        try {
            $request = $this->readOptionForUpdate(self::OPTION_REQUEST);
            if (!is_array($request) || !hash_equals((string)($request['request_hash']??''), $requestHash)) {
                return $this->rollbackError(new WP_Error('smai_future_activation_request_stale', 'Future-40 activation request is unavailable or stale.', ['status'=>409]));
            }
            if ((int)($request['proposed_by']??0) === $actorUserId) {
                return $this->rollbackError(new WP_Error('smai_future_activation_independence', 'Future-40 activation requires an independent approver.', ['status'=>403]));
            }
            $evidence = strtolower((string)($request['evidence_hash']??''));
            if (!self::protectedEvidenceMatches($evidence)) {
                return $this->rollbackError(new WP_Error('smai_future_activation_evidence_mismatch', 'Protected Future-40 evidence changed after proposal.', ['status'=>409]));
            }
            if (!RuntimeGate::schemaReady()) {
                return $this->rollbackError(new WP_Error('smai_future_schema_gate', 'CF-05 schema is not ready for Future-40 authorization.', ['status'=>409]));
            }
            foreach ([
                self::OPTION_STATE => 'approved',
                self::OPTION_APPROVED => '1',
                self::OPTION_EVIDENCE => $evidence,
                self::OPTION_APPROVED_BY => $actorUserId,
                self::OPTION_APPROVED_AT => gmdate('c'),
            ] as $name => $value) {
                if (!$this->writeOption($name, $value)) return $this->rollbackError(new WP_Error('smai_future_activation_store_failed', 'Future-40 authorization state could not be stored.', ['status'=>500]));
            }
            if (!$this->deleteOption(self::OPTION_REQUEST)) return $this->rollbackError(new WP_Error('smai_future_activation_store_failed', 'Future-40 pending proposal could not be finalized.', ['status'=>500]));
            if (!(new AuditLogger($this->db))->logInOpenTransaction('future40_activation_approved', 'future40_activation', (string)($request['request_uuid']??''), 'success', [
                'request_hash'=>$requestHash,'evidence_hash'=>$evidence,'base_runtime_state'=>RuntimeGate::state(),
            ], 'future40_governance', null, $actorUserId)) {
                return $this->rollbackError(new WP_Error('smai_future_audit_failed', 'Future-40 authorization was not committed because audit evidence could not be written.', ['status'=>500]));
            }
            if (!$this->commit()) return new WP_Error('smai_future_commit_failed', 'Future-40 authorization could not be committed.', ['status'=>500]);
            $this->flushOptionCaches([self::OPTION_REQUEST,self::OPTION_STATE,self::OPTION_APPROVED,self::OPTION_EVIDENCE,self::OPTION_APPROVED_BY,self::OPTION_APPROVED_AT]);
            return ['state'=>'approved','activation_approved'=>self::isApproved(),'approved_by'=>$actorUserId,'base_runtime_state'=>RuntimeGate::state()];
        } catch (\Throwable $error) {
            $this->rollback();
            return new WP_Error('smai_future_activation_store_failed', 'Future-40 authorization failed safely.', ['status'=>500]);
        }
    }

    /** @return array<string,mixed>|WP_Error */
    public function disable(string $reason, int $actorUserId): array|WP_Error
    {
        if ($actorUserId < 1 || !user_can($actorUserId, 'smai_approve_future_intelligence')) {
            return new WP_Error('smai_future_activation_forbidden', 'Future-40 disable operation is not authorized.', ['status'=>403]);
        }
        $reason = Text::truncate(trim(wp_strip_all_tags($reason)), 500);
        if (strlen($reason) < 8 || (new SensitiveValueDetector())->violations($reason) !== []) return new WP_Error('smai_future_disable_reason_required', 'A meaningful non-sensitive Future-40 disable reason is required.', ['status'=>400]);
        if (!$this->begin()) return new WP_Error('smai_future_transaction_failed', 'Future-40 disable transaction could not start.', ['status'=>500]);
        try {
            $previous = (string)($this->readOptionForUpdate(self::OPTION_STATE) ?? 'disabled');
            if (!$this->writeFailClosedState()) return $this->rollbackError(new WP_Error('smai_future_disable_failed', 'Future-40 could not be disabled safely.', ['status'=>500]));
            $audited = (new AuditLogger($this->db))->logInOpenTransaction('future40_activation_disabled','future40_activation',null,'success',['from'=>$previous,'to'=>'disabled','reason'=>$reason],'future40_governance',null,$actorUserId);
            if (!$audited) {
                if (!$this->commit()) return new WP_Error('smai_future_disable_failed', 'Future-40 fail-closed state could not be committed.', ['status'=>500]);
                $this->flushAllActivationCaches();
                return new WP_Error('smai_future_audit_failed_failclosed', 'Future-40 was disabled fail-closed, but audit evidence could not be written.', ['status'=>500,'state'=>'disabled']);
            }
            if (!$this->commit()) return new WP_Error('smai_future_disable_failed', 'Future-40 disable state could not be committed.', ['status'=>500]);
            $this->flushAllActivationCaches();
            return ['previous_state'=>$previous,'state'=>'disabled','activation_approved'=>false,'audit_recorded'=>true];
        } catch (\Throwable $error) {
            $this->rollback();
            self::failClosed();
            return new WP_Error('smai_future_disable_failed', 'Future-40 disable encountered an error and was forced fail-closed.', ['status'=>500,'state'=>'disabled']);
        }
    }

    public static function isApproved(): bool
    {
        if ((string)get_option(self::OPTION_STATE, 'disabled') !== 'approved' || get_option(self::OPTION_APPROVED, '0') !== '1') return false;
        $stored = strtolower((string)get_option(self::OPTION_EVIDENCE, ''));
        return self::protectedEvidenceMatches($stored);
    }

    private static function protectedEvidenceMatches(string $evidenceHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1 || !defined('SMAI_FUTURE40_EVIDENCE_SHA256') || !is_string(SMAI_FUTURE40_EVIDENCE_SHA256)) return false;
        $configured = strtolower(trim(SMAI_FUTURE40_EVIDENCE_SHA256));
        return preg_match('/^[a-f0-9]{64}$/', $configured) === 1 && hash_equals($configured, $evidenceHash);
    }

    private function begin(): bool { return $this->db->wpdb()->query('START TRANSACTION') !== false; }
    private function commit(): bool { $ok=$this->db->wpdb()->query('COMMIT')!==false; if(!$ok)$this->rollback(); return $ok; }
    private function rollback(): void { $this->db->wpdb()->query('ROLLBACK'); }
    private function rollbackError(WP_Error $error): WP_Error { $this->rollback(); return $error; }

    private function readOptionForUpdate(string $name): mixed
    {
        $wpdb=$this->db->wpdb();
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE",$name));
        return $raw===null ? null : maybe_unserialize($raw);
    }

    private function writeOption(string $name, mixed $value, bool $insertOnly=false): bool
    {
        $wpdb=$this->db->wpdb();$serialized=maybe_serialize($value);
        if($insertOnly) return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no')",$name,$serialized))===1;
        $exists=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE",$name));
        if($exists===null) return $wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no')",$name,$serialized))===1;
        return $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s,autoload='no' WHERE option_name=%s",$serialized,$name))!==false;
    }

    private function deleteOption(string $name): bool
    {
        $wpdb=$this->db->wpdb();
        return $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name=%s",$name))!==false;
    }

    private function writeFailClosedState(): bool
    {
        foreach ([self::OPTION_STATE=>'disabled',self::OPTION_APPROVED=>'0',self::OPTION_EVIDENCE=>''] as $name=>$value) if(!$this->writeOption($name,$value)) return false;
        foreach ([self::OPTION_REQUEST,self::OPTION_APPROVED_BY,self::OPTION_APPROVED_AT] as $name) if(!$this->deleteOption($name)) return false;
        return true;
    }

    private function flushOptionCaches(array $names): void { foreach($names as $name) wp_cache_delete($name,'options'); }
    private function flushAllActivationCaches(): void { $this->flushOptionCaches([self::OPTION_REQUEST,self::OPTION_STATE,self::OPTION_APPROVED,self::OPTION_EVIDENCE,self::OPTION_APPROVED_BY,self::OPTION_APPROVED_AT]); }

    private static function failClosed(): void
    {
        update_option(self::OPTION_STATE,'disabled',false);update_option(self::OPTION_APPROVED,'0',false);update_option(self::OPTION_EVIDENCE,'',false);
        delete_option(self::OPTION_REQUEST);delete_option(self::OPTION_APPROVED_BY);delete_option(self::OPTION_APPROVED_AT);
    }
}
