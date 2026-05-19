<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus\Middleware;

use App\Infrastructure\Bus\CommandMiddlewareInterface;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Wraps the rest of the command pipeline (and the handler) in a database
 * transaction (B8).
 *
 * Begin → run handler → commit on success → rollback on any \Throwable.
 *
 * The transaction wraps event dispatch as well: if any synchronous listener
 * throws, the entire write is rolled back. This makes event side-effects
 * part of the same unit of work as the entity write — the simplest
 * approximation of an outbox until we add one.
 *
 * Read-only command-like operations (e.g. login attempts that record a row
 * AND issue tokens) still benefit from transactions; the only commands that
 * should NOT use this middleware are those that explicitly manage their own
 * transactions (currently none).
 */
final readonly class TransactionMiddleware implements CommandMiddlewareInterface
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private LoggerInterface $logger,
        private ?BaseConnection $db = null
    ) {
    }

    public function handle(object $command, callable $next): mixed
    {
        $db = $this->db ?? Database::connect();

        $db->transBegin();

        try {
            $result = $next($command);
        } catch (\Throwable $e) {
            $db->transRollback();
            $this->logger->warning('Command transaction rolled back', [
                'component' => 'CommandBus',
                'command_class' => $command::class,
                'exception' => $e->getMessage(),
                'correlation_id' => CorrelationIdService::get(),
            ]);
            throw $e;
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            $this->logger->warning('Command transaction rolled back (status=false)', [
                'component' => 'CommandBus',
                'command_class' => $command::class,
                'correlation_id' => CorrelationIdService::get(),
            ]);

            throw new \RuntimeException(
                sprintf('Transaction failed during %s', $command::class)
            );
        }

        $db->transCommit();

        return $result;
    }
}
