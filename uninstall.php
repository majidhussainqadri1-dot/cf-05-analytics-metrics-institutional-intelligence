<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Governance and audit data are retained by default. Destructive removal requires
// an explicit, separately reviewed operational process and is never automatic.
delete_option('smai_runtime_state');
delete_option('smai_activation_approved');
delete_option('smai_activation_evidence_hash');
delete_option('smai_minimum_cohort');
delete_option('smai_raw_retention_days');
delete_option('smai_quarantine_retention_days');
wp_clear_scheduled_hook('smai_daily_retention');
