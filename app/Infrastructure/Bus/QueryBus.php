<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Bus\QueryHandlerInterface;
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
     * @var array<string, QueryHandlerInterface<object, mixed>> Format: [QueryClassName => HandlerInstance]
     */
    private array $handlers = [];

    /**
     * Register a query handler.
     *
     * The handler must implement {@see QueryHandlerInterface}. Any
     * attempt to register a non-implementing handler fails at
     * register-time with a PHP TypeError (closes 04/F3).
     *
     * @template TQuery of object
     * @template TResult
     * @param class-string                           $queryClass Fully qualified query class name.
     * @param QueryHandlerInterface<TQuery, TResult> $handler    The handler instance.
     * @return void
     * @throws RuntimeException If handler is already registered for this query.
     */
    public function register(string $queryClass, QueryHandlerInterface $handler): void
    {
        if (isset($this->handlers[$queryClass])) {
            throw new RuntimeException(
                sprintf('Handler for query "%s" is already registered', $queryClass)
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
