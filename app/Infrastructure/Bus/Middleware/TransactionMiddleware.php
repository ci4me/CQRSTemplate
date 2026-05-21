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
     * @param LoggerInterface                                                   $logger
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     * @param \Closure|null                                                     $dispatcherResolver * @param (\Closure(): ?EventDispatcher)|null $dispatcherResolver
     *                                                     Lazy resolver for the shared EventDispatcher. Resolved at handle()
     *                                                     time so Services::commandBus(false) does not have to invoke
     *                                                     Services::eventDispatcher() during bus construction — which would
     *                                                     recurse through ensureProvidersRegistered() before either bus is
     *                                                     cached. Pass `null` to disable strict event-dispatch mode (CLI
     *                                                     scripts, tests with isolated dispatchers).
     */
    public function __construct(
        private LoggerInterface $logger,
        private ?BaseConnection $db = null,
        private ?\Closure $dispatcherResolver = null
    ) {
    }

    /**
     * handle.
     *
     * @param object   $command
     * @param callable $next
     * @return mixed
     * @throws \RuntimeException
     */
    public function handle(object $command, callable $next): mixed
    {
        $db = $this->db ?? Database::connect();

        // Strict event-dispatch mode: while we're inside the transaction,
        // synchronous-listener failures must fail the whole command so the
        // entity write is rolled back atomically. We restore the previous
        // value in a finally so nested calls / tests are unaffected.
        $dispatcher = null;
        $previousRethrow = false;
        if ($this->dispatcherResolver !== null) {
            $dispatcher = ($this->dispatcherResolver)();
        }
        if ($dispatcher !== null) {
            $previousRethrow = $dispatcher->setRethrowOnListenerFailure(true);
        }

        $db->transBegin();

        try {
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
        } finally {
            if ($dispatcher !== null) {
                $dispatcher->setRethrowOnListenerFailure($previousRethrow);
            }
        }
    }
}
