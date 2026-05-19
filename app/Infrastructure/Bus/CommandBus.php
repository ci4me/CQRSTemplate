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
 *
 * CQRS Pattern - Commands:
 * Commands represent INTENT to change system state. They:
 * - Are named in imperative (CreateCookie, UpdateCookie)
 * - Contain all data needed for the operation
 * - Do not return domain data (only success indicators or IDs)
 * - Are handled by exactly ONE handler
 *
 * Why use a Command Bus:
 * - Single responsibility: Controllers don't know about business logic
 * - Testability: Easy to test handlers in isolation
 * - Flexibility: Easy to add middleware (logging, transactions, etc.)
 * - Consistency: All commands follow the same pattern
 *
 * Usage Example:
 * ```php
 * $command = new CreateCookieCommand(
 *     name: 'Chocolate Chip',
 *     price: '2.99'
 * );
 * $cookieId = $commandBus->dispatch($command);
 * ```
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
     * Register a command handler.
     *
     * @param string $commandClass Fully qualified command class name
     * @param object $handler The handler instance with handle() method
     * @throws RuntimeException If handler is already registered for this command
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
     * Dispatch a command to its handler.
     *
     * @param object $command The command to dispatch
     * @return mixed The result from the handler (typically an ID or void)
     * @throws DomainException If no handler is registered for this command
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

        // Verify handler has handle() method
        if (!method_exists($handler, 'handle')) {
            throw new DomainException(
                sprintf('Handler for command "%s" does not have a handle() method', $commandClass)
            );
        }

        // Call the handler's handle() method
        return $handler->handle($command);
    }

    /**
     * Check if a handler is registered for a command.
     *
     * @param string $commandClass The command class name
     * @return bool True if handler is registered
     */
    public function hasHandler(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }
}
