<?php

declare(strict_types=1);

namespace App\Commands;

use App\Infrastructure\Jobs\JobWorker;
use App\Infrastructure\Logging\CorrelationIdService;
use App\Infrastructure\Logging\LoggerFactory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Drain pending jobs from the `jobs` table (D6).
 *
 * Usage:
 *   php spark jobs:work                              # drain default queue once
 *   php spark jobs:work --queue emails --batch 5     # drain "emails", up to 5
 *   php spark jobs:work --watch                      # loop forever, 1s tick
 *
 * Run under a supervisor (systemd/supervisord) in production. Safe to run
 * multiple workers concurrently — claim semantics rely on a single
 * UPDATE WHERE status='pending'.
 */
final class WorkJobs extends BaseCommand
{
    /** @var string */
    protected $group = 'Jobs';

    /** @var string */
    protected $name = 'jobs:work';

    /** @var string */
    protected $description = 'Process pending jobs from the queue';

    /** @var string */
    protected $usage = 'jobs:work [--queue=NAME] [--batch=N] [--watch] [--sleep=SECONDS]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--queue' => 'Queue name to consume from (default "default")',
        '--batch' => 'Maximum jobs to drain per pass (default 10)',
        '--watch' => 'Loop forever, sleeping between passes',
        '--sleep' => 'Seconds to sleep between empty passes in --watch mode',
    ];

    /**
     * @param array<int|string, mixed> $params
     */
    public function run(array $params): int
    {
        $queue = (string) (CLI::getOption('queue') ?? 'default');
        $batch = (int) (CLI::getOption('batch') ?? 10);
        $watch = CLI::getOption('watch') !== null;
        $sleep = max(1, (int) (CLI::getOption('sleep') ?? 1));

        $worker = new JobWorker(LoggerFactory::create('infrastructure.job_worker'));

        // OPERABILITY: same SIGTERM/SIGINT pattern as RelayOutboxEvents so
        // supervisor stops don't orphan claimed jobs mid-pass.
        $shouldStop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stopHandler = static function () use (&$shouldStop): void {
                $shouldStop = true;
            };
            pcntl_signal(SIGTERM, $stopHandler);
            pcntl_signal(SIGINT, $stopHandler);
        }

        do {
            // OBSERVABILITY: fresh correlation id per drain pass.
            CorrelationIdService::clear();

            $stats = $worker->drain($queue, $batch);
            CLI::write(sprintf(
                'jobs:work queue=%s — processed=%d succeeded=%d retried=%d failed=%d',
                $queue,
                $stats['processed'],
                $stats['succeeded'],
                $stats['retried'],
                $stats['failed']
            ), 'green');

            if ($shouldStop) {
                CLI::write('jobs:work: SIGTERM/SIGINT received — exiting between drains', 'yellow');
                break;
            }

            if ($watch && $stats['processed'] === 0) {
                sleep($sleep);
            }
        } while ($watch);

        return 0;
    }
}
