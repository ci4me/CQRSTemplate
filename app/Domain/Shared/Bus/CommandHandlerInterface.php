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
 *       public function handle(CreateCookieCommand $command): int { ... }
 *   }
 *
 * Note: the native parameter type stays `object` so PHP's LSP rules accept
 * the narrowing (`CreateCookieCommand`) in subtypes. PHPStan still sees the
 * narrowed type via @param.
 *
 * @template TCommand of object
 * @template TResult
 * @package App\Domain\Shared\Bus
 */
interface CommandHandlerInterface
{
    /**
     * Execute the command and return the handler-specific result.
     *
     * Concrete handlers narrow the parameter type to their specific
     * command class — PHP's LSP allows the contravariant narrowing when
     * the base parameter is the bare `object` placeholder.
     *
     * @param TCommand $command The command DTO to execute.
     * @return TResult Handler-specific return value.
     */
    public function handle(object $command): mixed;
}
