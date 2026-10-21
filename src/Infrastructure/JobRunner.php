<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

use Sabri\AnalyticsIntelligence\Domain\BackfillService;
use Sabri\AnalyticsIntelligence\Domain\DeletionService;
use Sabri\AnalyticsIntelligence\Domain\ExportService;
use Sabri\AnalyticsIntelligence\Domain\QualityService;
use Sabri\AnalyticsIntelligence\Domain\PipelineService;
use Sabri\AnalyticsIntelligence\Domain\ReportService;
use Sabri\AnalyticsIntelligence\Domain\SnapshotService;

final class JobRunner
{
    private Database $db;
    private JobQueue $queue;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->queue = new JobQueue($db);
    }

    public function register(): void
    {
        add_action('smai_run_jobs', [$this, 'run']);
    }

    public function run(): void
    {
        if (!RuntimeGate::workerEnabled()) {
            return;
        }
        $worker = 'wp-cron:' . substr(hash('sha256', (string) gethostname() . '|' . getmypid()), 0, 16);
        $deadline = microtime(true) + 20.0;
        $processed = 0;
        while ($processed < 20 && microtime(true) < $deadline) {
            $job = $this->queue->claim($worker);
            if ($job === null) {
                break;
            }
            $processed++;
            try {
                $payload = (array) $job['payload'];
                $payload['_job_uuid'] = (string) $job['job_uuid'];
                $payload['_job_attempt'] = (int) $job['attempts'];
                $payload['_job_max_attempts'] = (int) $job['max_attempts'];
                $result = $this->dispatch((string) $job['job_type'], $payload);
                if (!$this->queue->complete((string) $job['job_uuid'], $worker, $result)) {
                    $this->queue->fail((string) $job['job_uuid'], $worker, 'job_completion_persist_failed', 'The governed worker could not persist successful completion.');
                }
            } catch (\Throwable $error) {
                $this->queue->fail(
                    (string) $job['job_uuid'],
                    $worker,
                    'job_exception',
                    'The governed worker failed; see trace evidence.'
                );
            }
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function dispatch(string $type, array $payload): array
    {
        return match ($type) {
            'pipeline.process_event' => (new PipelineService($this->db))->runJob($payload),
            'snapshot.compute' => (new SnapshotService($this->db))->runJob($payload),
            'quality.run' => (new QualityService($this->db))->runJob($payload),
            'backfill.run' => (new BackfillService($this->db))->runJob($payload),
            'export.build' => (new ExportService($this->db))->runJob($payload),
            'report.run' => (new ReportService($this->db))->runJob($payload),
            'deletion.apply' => (new DeletionService($this->db))->runJob($payload),
            default => throw new \RuntimeException('Unknown CF-05 job type.'),
        };
    }
}
