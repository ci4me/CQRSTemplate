<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus\Middleware;

/**
 * Interface for bus middleware (Command/Query).
 *
 * Middleware wraps the handler execution and can perform
 * cross-cutting concerns like transactions, logging, etc.
 *
 * @package App\Infrastructure\Bus\Middleware
 */
interface BusMiddlewareInterface
{
    public function handle(object $message, callable $next): mixed;
}
