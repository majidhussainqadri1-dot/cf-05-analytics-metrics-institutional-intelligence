<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class RuntimeGate
{
    public const FOUNDATION_DISABLED = 'foundation_disabled';
    public const CATALOG_ONLY = 'catalog_only';
    public const STAGING_ACTIVE = 'staging_active';
    public const PRODUCTION_ACTIVE = 'production_active';
    public const SAFE_MODE = 'safe_mode';

    public static function state(): string
    {
        $state = (string) get_option('smai_runtime_state', self::FOUNDATION_DISABLED);
        return in_array($state, [self::FOUNDATION_DISABLED, self::CATALOG_ONLY, self::STAGING_ACTIVE, self::PRODUCTION_ACTIVE, self::SAFE_MODE], true)
            ? $state
            : self::SAFE_MODE;
    }

    public static function activationApproved(): bool
    {
        $stored = strtolower((string) get_option('smai_activation_evidence_hash', ''));
        if (get_option('smai_activation_approved', '0') !== '1' || preg_match('/^[a-f0-9]{64}$/', $stored) !== 1) {
            return false;
        }
        if (!defined('SMAI_ACTIVATION_EVIDENCE_SHA256') || !is_string(SMAI_ACTIVATION_EVIDENCE_SHA256)) {
            return false;
        }
        $configured = strtolower(SMAI_ACTIVATION_EVIDENCE_SHA256);
        return preg_match('/^[a-f0-9]{64}$/', $configured) === 1 && hash_equals($stored, $configured);
    }

    public static function ingestionEnabled(): bool
    {
        return self::activeRuntimeIsEnvironmentCompatible() && self::activationApproved();
    }

    public static function queryEnabled(): bool
    {
        return self::activeRuntimeIsEnvironmentCompatible() && self::activationApproved();
    }

    public static function isProduction(): bool
    {
        return self::state() === self::PRODUCTION_ACTIVE;
    }

    private static function activeRuntimeIsEnvironmentCompatible(): bool
    {
        $state = self::state();
        if ($state === self::PRODUCTION_ACTIVE) {
            return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
        }
        if ($state === self::STAGING_ACTIVE) {
            if (!function_exists('wp_get_environment_type')) {
                return false;
            }
            return in_array(wp_get_environment_type(), ['staging', 'development', 'local'], true);
        }
        return false;
    }
}
