<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\CLI;

use Sabri\AnalyticsIntelligence\Domain\AccessProjectService;
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Infrastructure\AuditVerifier;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;
use Sabri\AnalyticsIntelligence\Infrastructure\HealthService;
use Sabri\AnalyticsIntelligence\Infrastructure\JobRunner;
use Sabri\AnalyticsIntelligence\Infrastructure\RepairService;
use Sabri\AnalyticsIntelligence\Infrastructure\RetentionRunner;

final class Commands
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        \WP_CLI::add_command('smai health', function (): void {
            \WP_CLI::print_value((new HealthService($this->db))->report(true), ['format' => 'json']);
        });
        \WP_CLI::add_command('smai jobs', function (): void {
            (new JobRunner($this->db))->run();
            \WP_CLI::success('CF-05 job runner completed its bounded cycle.');
        });
        \WP_CLI::add_command('smai retention', function (): void {
            (new RetentionRunner($this->db))->run();
            \WP_CLI::success('CF-05 retention cycle completed.');
        });
        \WP_CLI::add_command('smai reports', function (): void {
            $count = (new ReportService($this->db))->scheduleDue();
            \WP_CLI::success("Queued {$count} due reports.");
        });
        \WP_CLI::add_command('smai access-expiry', function (): void {
            $count = (new AccessProjectService($this->db))->expireDue();
            \WP_CLI::success("Expired {$count} access projects.");
        });
        \WP_CLI::add_command('smai audit-verify', function (): void {
            $result = (new AuditVerifier($this->db))->verify();
            \WP_CLI::print_value($result, ['format' => 'json']);
            if ($result['status'] !== 'verified') {
                \WP_CLI::error('Audit chain verification failed.');
            }
        });
        \WP_CLI::add_command('smai repair', function (): void {
            \WP_CLI::print_value((new RepairService($this->db))->safeRepair(), ['format' => 'json']);
        });
    }
}
