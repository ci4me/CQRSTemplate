<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Exceptions\DomainException;
use RuntimeException;

/**
 * Command Bus for handling write operations (Commands).
 *
 * The Command Bus is responsible for:
 * - Routing commands to their appropriate handlers
 * - Ensuring one command = one handler (no ambiguity)
 * - Decoupling controllers from command handlers
 * - Providing a single entry point for all write operations
 * - Running registered {@see CommandMiddlewareInterface} around every dispatch
 *
 * Middleware pipeline (C3):
 * Middlewares are wrapped around the handler in registration order; the
 * first middleware registered is the outermost. Typical layering:
 *   LoggingMiddleware -> TransactionMiddleware -> handler
 * so that the log entry captures the transaction outcome AND the handler
 * runs inside the transaction.
 *
 * @package App\Infrastructure\Bus
 */
final class CommandBus
{
    /**
     * Map of command class names to their handler instances.
     *
     * @var array<string, object> Format: [CommandClassName => HandlerInstance]
     */
    private array $handlers = [];

    /**
     * Pipeline of middlewares executed in registration order.
     *
     * @var list<CommandMiddlewareInterface>
     */
    private array $middleware = [];

    public function pushMiddleware(CommandMiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Replace the middleware pipeline (useful in tests).
     *
     * @param list<CommandMiddlewareInterface> $middleware
     */
    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    /**
     * Register a command handler.
     */
    public function register(string $commandClass, object $handler): void
    {
        if (isset($this->handlers[$commandClass])) {
            throw new RuntimeException(
                sprintf('Handler for command "%s" is already registered', $commandClass)
            );
        }

        $this->handlers[$commandClass] = $handler;
    }

    /**
     * Dispatch a command through the middleware pipeline to its handler.
     */
    public function dispatch(object $command): mixed
    {
        $commandClass = $command::class;

        if (!isset($this->handlers[$commandClass])) {
            throw new DomainException(
                sprintf('No handler registered for command "%s"', $commandClass)
            );
        }

        $handler = $this->handlers[$commandClass];

        if (!method_exists($handler, 'handle')) {
            throw new DomainException(
                sprintf('Handler for command "%s" does not have a handle() method', $commandClass)
            );
        }

        // Build the pipeline: outermost middleware -> ... -> handler invocation.
        $core = static fn(object $c): mixed => $handler->handle($c);

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn(callable $next, CommandMiddlewareInterface $mw): callable
                => static fn(object $c): mixed => $mw->handle($c, $next),
            $core
        );

        return $pipeline($command);
    }

    public function hasHandler(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }
}
