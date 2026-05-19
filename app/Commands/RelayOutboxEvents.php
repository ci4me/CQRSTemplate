<?php

declare(strict_types=1);

namespace App\Commands;

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

        do {
            $stats = $relay->drain($batch);
            CLI::write(sprintf(
                'relay pass — processed=%d delivered=%d retried=%d failed=%d',
                $stats['processed'],
                $stats['delivered'],
                $stats['retried'],
                $stats['failed']
            ), 'green');

            if ($watch && $stats['processed'] === 0) {
                sleep($sleep);
            }
        } while ($watch);

        return 0;
    }
}
