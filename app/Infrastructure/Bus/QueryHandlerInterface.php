<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

/**
 * Marker interface for query handlers.
 *
 * QueryBus is duck-typed (it only checks `method_exists($handler, 'handle')`)
 * so historical handlers do not need to implement this interface. New
 * handlers SHOULD implement it: the interface documents the contract
 * and lets PHPStan reason about handler shapes.
 *
 * Convention: query handlers return a DTO (`{Entity}View`) or a list of
 * DTOs — NEVER a domain entity. Domain entities carry behaviour and
 * encapsulation that read paths must not depend on. Return `mixed` here
 * because the bus is type-erased.
 */
interface QueryHandlerInterface
{
    /**
     * Execute the query and return a read-model. Concrete handlers
     * narrow the parameter to their specific query class.
     */
    public function handle(object $query): mixed;
}
