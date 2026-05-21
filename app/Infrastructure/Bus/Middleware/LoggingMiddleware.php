<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus\Middleware;

use App\Infrastructure\Bus\CommandMiddlewareInterface;
use App\Infrastructure\Logging\CorrelationIdService;
use Psr\Log\LoggerInterface;

/**
 * Logs every command dispatched through the {@see \App\Infrastructure\Bus\CommandBus}.
 *
 * Records start, success (with duration), or failure (with exception details).
 * Each entry carries the correlation id so traces stitch across handlers,
 * repositories, and events.
 *
 * The command payload is NOT logged here on purpose: command DTOs often
 * contain sensitive fields (passwords, tokens). When a domain decides that
 * specific commands ARE safe to log in detail, the handler itself can emit
 * a targeted info() with a redacted payload.
 */
final readonly class LoggingMiddleware implements CommandMiddlewareInterface
{
    /**
     * __construct.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * handle.
     *
     * @param object   $command
     * @param callable $next
     * @return mixed
     */
    public function handle(object $command, callable $next): mixed
    {
        $commandClass = $command::class;
        $startMs = microtime(true) * 1000;

        $this->logger->info('Command dispatched', [
            'component' => 'CommandBus',
            'command_class' => $commandClass,
            'correlation_id' => CorrelationIdService::get(),
        ]);

        try {
            $result = $next($command);
        } catch (\Throwable $e) {
            $this->logger->error('Command failed', [
                'component' => 'CommandBus',
                'command_class' => $commandClass,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'duration_ms' => round(microtime(true) * 1000 - $startMs, 2),
                'correlation_id' => CorrelationIdService::get(),
            ]);
            throw $e;
        }

        $this->logger->info('Command succeeded', [
            'component' => 'CommandBus',
            'command_class' => $commandClass,
            'duration_ms' => round(microtime(true) * 1000 - $startMs, 2),
            'correlation_id' => CorrelationIdService::get(),
        ]);

        return $result;
    }
}
