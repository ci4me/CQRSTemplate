<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Bus\CommandHandlerInterface;
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
     * Handlers are constrained at registration time to implement
     * {@see CommandHandlerInterface}; the array stores the validated
     * instances so dispatch() can invoke them directly without re-checking.
     *
     * The generic parameters are intentionally erased here — the bus is
     * heterogeneous (different commands carry different concrete
     * handlers), and a single typed envelope can't capture that. The
     * register-time interface enforcement is what matters; PHPStan
     * narrows on the handler side via @implements.
     *
     * @var array<string, CommandHandlerInterface<object, mixed>>
     */
    private array $handlers = [];

    /**
     * Pipeline of middlewares executed in registration order.
     *
     * @var list<CommandMiddlewareInterface>
     */
    private array $middleware = [];

    /**
     * pushMiddleware.
     *
     * @param CommandMiddlewareInterface $middleware
     * @return void
     */
    public function pushMiddleware(CommandMiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Replace the middleware pipeline (useful in tests).
     *
     * @param list<CommandMiddlewareInterface> $middleware
     * @return void
     */
    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    /**
     * Register a command handler.
     *
     * The handler must implement {@see CommandHandlerInterface}. Any
     * attempt to register a handler that does not implement the
     * interface fails at register-time with a PHP TypeError, NOT at
     * the first dispatch call site — accidental typos in handler
     * files surface at boot rather than on the first user request
     * (closes 03/F5, 04/F3, 03/F16).
     *
     * @template TCommand of object
     * @template TResult
     * @param class-string                               $commandClass FQCN of the command DTO.
     * @param CommandHandlerInterface<TCommand, TResult> $handler      The handler instance.
     * @return void
     * @throws RuntimeException If a handler is already registered for $commandClass.
     */
    public function register(string $commandClass, CommandHandlerInterface $handler): void
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
     *
     * @param object $command
     * @return mixed
     * @throws DomainException
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

        // Build the pipeline: outermost middleware -> ... -> handler invocation.
        // No method_exists() check here: register() already enforces the
        // CommandHandlerInterface contract, so the call is guaranteed safe.
        $core = static fn(object $c): mixed => $handler->handle($c);

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn(callable $next, CommandMiddlewareInterface $mw): callable
                => static fn(object $c): mixed => $mw->handle($c, $next),
            $core
        );

        return $pipeline($command);
    }

    /**
     * hasHandler.
     *
     * @param string $commandClass
     * @return bool
     */
    public function hasHandler(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }
}
