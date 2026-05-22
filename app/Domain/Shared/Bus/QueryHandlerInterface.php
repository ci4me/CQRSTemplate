<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Typed contract for query handlers.
 *
 * Mirrors {@see CommandHandlerInterface} for the read side. Once a handler
 * implements this interface, QueryBus::register() will accept it via the
 * typed parameter (no more duck-typing), and PHPStan can drop the
 * `method.notFound` suppression at the bus dispatch site.
 *
 * Convention: query handlers return a DTO or a list of DTOs — NEVER a
 * domain entity. Domain entities carry write-side behaviour that read
 * paths must not couple to.
 *
 * Generic parameters:
 *   TQuery  — the concrete query DTO class.
 *   TResult — the read-model the handler returns (DTO, list, or null).
 *
 * @template TQuery of object
 * @template TResult
 * @package App\Domain\Shared\Bus
 */
interface QueryHandlerInterface
{
    /**
     * Execute the query and return the read-model.
     *
     * Concrete handlers narrow the parameter type to their specific query
     * class.
     *
     * @param TQuery $query The query DTO to execute.
     * @return TResult The read-model (DTO / list / null).
     */
    public function handle(object $query): mixed;
}
