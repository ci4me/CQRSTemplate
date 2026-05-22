<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Typed contract for command handlers.
 *
 * Replaces the legacy duck-typed `method_exists($handler, 'handle')` check
 * the CommandBus used to perform at registration time. With this interface
 * in place:
 *
 *   - CommandBus::register() typehints CommandHandlerInterface, so any
 *     handler that forgets the implements clause fails at register-time
 *     (TypeError) rather than at the first dispatch call site.
 *   - PHPStan resolves the @template parameters end-to-end, so the
 *     "method.notFound" suppression on $handler->handle() is no longer
 *     needed inside the bus.
 *
 * Generic parameters:
 *   TCommand — the concrete command DTO class the handler accepts.
 *   TResult  — the value the handler returns (e.g. int for `Create*`,
 *              void for everything else; declared as mixed at the bus
 *              boundary because the bus is type-erased).
 *
 * Concrete handlers declare:
 *
 *   final class CreateCookieHandler implements CommandHandlerInterface
 *   {
 *       public function handle(object $command): int { ... }
 *   }
 *
 * Signature shape notes:
 *
 *  - The native PARAMETER type stays the bare `object` placeholder
 *    (PHP rejects narrowing a typed parameter in subtypes). Subclasses
 *    annotate `@param SpecificCommand $command` so PHPStan narrows the
 *    body type without breaking PHP's LSP rules.
 *
 *  - The native RETURN type is intentionally OMITTED. Omitting it lets
 *    concrete handlers preserve their own precise return type —
 *    `int` (Create*), `void` (Update/Delete/Restore), or a value
 *    object (LoginUser → AuthenticationResult) — without tripping
 *    PHP's incompatibility rule between `void` and `mixed`. PHPStan
 *    still infers the precise return through `@return TResult`.
 *
 * @template TCommand of object
 * @template-covariant TResult
 * @package App\Domain\Shared\Bus
 */
interface CommandHandlerInterface
{
    /**
     * Execute the command and return the handler-specific result.
     *
     * @param TCommand $command The command DTO to execute.
     * @return TResult Handler-specific return value.
     */
    public function handle(object $command);
}
