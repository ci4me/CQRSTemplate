<?php

declare(strict_types=1);

namespace App\Commands;

use App\Infrastructure\Logging\CorrelationIdService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Outbox\EventOutboxRelay;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * Relay pending domain events from `event_outbox` to the in-process
 * EventDispatcher (C2).
 *
 * Usage:
 *   php spark events:relay              # drain up to 50 rows once
 *   php spark events:relay --batch 200  # drain up to 200 rows once
 *   php spark events:relay --watch      # loop forever, sleep 1s between drains
 *
 * Schedule (recommended): run continuously under a process supervisor
 * (systemd, supervisord), OR every minute via cron with the default
 * single-shot mode. The relay is safe to run on multiple workers.
 */
final class RelayOutboxEvents extends BaseCommand
{
    /** @var string */
    protected $group = 'Events';

    /** @var string */
    protected $name = 'events:relay';

    /** @var string */
    protected $description = 'Dispatch pending events from the event_outbox table';

    /** @var string */
    protected $usage = 'events:relay [--batch=N] [--watch] [--sleep=SECONDS]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--batch' => 'Maximum rows to drain per pass (default 50)',
        '--watch' => 'Loop forever, sleeping between passes',
        '--sleep' => 'Seconds to sleep between passes in --watch mode (default 1)',
    ];

    /**
     * @param array<int|string, mixed> $params
     * @return int
     */
    public function run(array $params): int
    {
        $batch = (int) (CLI::getOption('batch') ?? 50);
        $watch = CLI::getOption('watch') !== null;
        $sleep = max(1, (int) (CLI::getOption('sleep') ?? 1));

        $relay = new EventOutboxRelay(
            dispatcher: Services::eventDispatcher(),
            logger: LoggerFactory::create('infrastructure.outbox_relay')
        );

        // OPERABILITY: handle SIGTERM/SIGINT gracefully so the supervisor
        // (systemd, supervisord, docker stop) can stop us between drains
        // instead of killing mid-row, which would leave the in_flight row
        // stuck until a manual reap. pcntl_async_signals lets the handler
        // fire between PHP opcodes without explicit pcntl_signal_dispatch().
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
            // OBSERVABILITY: clear the static correlation id before each
            // drain pass so the next batch of rows gets a fresh trace
            // (each row adopts its row.correlation_id inside processRow).
            // Without this, idle --watch loops leak the first pass's id
            // into every subsequent pass's diagnostics.
            CorrelationIdService::clear();

            $stats = $relay->drain($batch);
            CLI::write(sprintf(
                'relay pass — processed=%d delivered=%d retried=%d failed=%d',
                $stats['processed'],
                $stats['delivered'],
                $stats['retried'],
                $stats['failed']
            ), 'green');

            if ($shouldStop) {
                CLI::write('relay: SIGTERM/SIGINT received — exiting between drains', 'yellow');
                break;
            }

            if ($watch && $stats['processed'] === 0) {
                sleep($sleep);
            }
        } while ($watch);

        return 0;
    }
}
