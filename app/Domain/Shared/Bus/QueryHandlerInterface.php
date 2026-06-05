<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Marker interface every query handler implements (round-4 R2 / E05).
 *
 * Lives in the DOMAIN layer (not Infrastructure) so domain handlers can
 * implement it without violating the Domain -> Infrastructure boundary;
 * the Infrastructure QueryBus depends downward on this contract, which is
 * the allowed direction.
 *
 * Intentionally declares NO handle() signature: concrete handlers narrow
 * the parameter to their specific query class, and PHP's parameter
 * contravariance forbids narrowing an inherited signature. The structural
 * guarantee lives in the bus instead — {@see \App\Infrastructure\Bus\QueryBus::register()}
 * verifies handle() exists and captures it as a typed Closure, so dispatch
 * never duck-types.
 *
 * Convention: query handlers return a DTO ({@see \App\Domain\Cookie\DTOs\CookieDTO})
 * or a list/array-shape of DTOs — NEVER a domain entity.
 */
interface QueryHandlerInterface
{
}
