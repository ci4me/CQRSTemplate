<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Exceptions\DomainException;
use RuntimeException;

/**
 * Query Bus for handling read operations (Queries).
 *
 * The Query Bus is responsible for:
 * - Routing queries to their appropriate handlers
 * - Ensuring one query = one handler (no ambiguity)
 * - Decoupling controllers from query handlers
 * - Providing a single entry point for all read operations
 *
 * CQRS Pattern - Queries:
 * Queries represent requests for DATA without changing state. They:
 * - Are named as questions (GetCookieById, GetAllCookies)
 * - Contain only filtering/pagination parameters
 * - ALWAYS return data (never modify state)
 * - Are handled by exactly ONE handler
 *
 * Why separate Query Bus from Command Bus:
 * - Clear separation of reads vs writes (CQRS principle)
 * - Different optimization strategies (read vs write)
 * - Different scaling strategies (read replicas, caching, etc.)
 * - Explicit intent (query can't modify state)
 *
 * Usage Example:
 * ```php
 * $query = new GetCookieByIdQuery(id: 5);
 * $cookie = $queryBus->ask($query);
 * ```
 *
 * @package App\Infrastructure\Bus
 */
final class QueryBus
{
    /**
     * Map of query class names to their handler instances.
     *
     * @var array<string, object> Format: [QueryClassName => HandlerInstance]
     */
    private array $handlers = [];

    /**
     * Register a query handler.
     *
     * @param string $queryClass Fully qualified query class name
     * @param object $handler The handler instance with handle() method
     * @throws RuntimeException If handler is already registered for this query
     */
    public function register(string $queryClass, object $handler): void
    {
        if (isset($this->handlers[$queryClass])) {
            throw new RuntimeException(
                sprintf('Handler for query "%s" is already registered', $queryClass)
            );
        }

        if (!method_exists($handler, 'handle')) {
            throw new RuntimeException(
                sprintf('Handler for query "%s" must have a handle() method', $queryClass)
            );
        }

        $this->handlers[$queryClass] = $handler;
    }

    /**
     * Ask a query and get the result.
     *
     * Note: Using "ask" instead of "dispatch" to emphasize that
     * queries return data (you're asking a question).
     *
     * @param object $query The query to execute
     * @return mixed The data returned by the handler
     * @throws DomainException If no handler is registered for this query
     */
    public function ask(object $query): mixed
    {
        $queryClass = $query::class;

        if (!isset($this->handlers[$queryClass])) {
            throw new DomainException(
                sprintf('No handler registered for query "%s"', $queryClass)
            );
        }

        $handler = $this->handlers[$queryClass];

        /** @phpstan-ignore method.notFound (handle() verified at registration time) */
        return $handler->handle($query);
    }

    /**
     * Check if a handler is registered for a query.
     *
     * @param string $queryClass The query class name
     * @return bool True if handler is registered
     */
    public function hasHandler(string $queryClass): bool
    {
        return isset($this->handlers[$queryClass]);
    }
}
