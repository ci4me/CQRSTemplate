<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Marker interface every command handler implements (round-4 R2 / E05).
 *
 * Lives in the DOMAIN layer (not Infrastructure) so domain handlers can
 * implement it without violating the Domain -> Infrastructure boundary;
 * the Infrastructure CommandBus depends downward on this contract, which
 * is the allowed direction.
 *
 * Intentionally declares NO handle() signature: concrete handlers narrow
 * the parameter to their specific command class (`handle(CreateCookieCommand
 * $command): int`), and PHP's parameter contravariance forbids narrowing an
 * inherited signature. The structural guarantee lives in the bus instead —
 * {@see \App\Infrastructure\Bus\CommandBus::register()} verifies handle()
 * exists and captures it as a typed Closure, so dispatch never duck-types.
 *
 * What the marker buys:
 *  - self-documenting handler classes (greppable inventory of the write side);
 *  - a hook for PHPStan rules (e.g. "only *Handler classes implement this");
 *  - a future migration path to a generics-typed registration signature.
 *
 * Convention: command handlers return the new aggregate id (int) for
 * `Create*` commands and `void` for everything else.
 */
interface CommandHandlerInterface
{
}
