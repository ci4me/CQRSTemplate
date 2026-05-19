<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

/**
 * Middleware that wraps command execution on the {@see CommandBus}.
 *
 * Middlewares form a pipeline executed in registration order. Each middleware
 * receives the command and a continuation; it may run code before/after
 * calling the continuation, return early, transform the result, or rethrow.
 *
 * Typical implementations: transaction, logging, audit, validation, retry.
 */
interface CommandMiddlewareInterface
{
    /**
     * @param callable(object): mixed $next Continuation that invokes the next
     *                                      middleware or the final handler.
     */
    public function handle(object $command, callable $next): mixed;
}
