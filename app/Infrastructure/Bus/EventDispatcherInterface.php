<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

interface EventDispatcherInterface
{
    public function subscribe(string $eventClass, callable $listener): void;

    public function dispatch(object $event): void;

    /**
     * Toggle "rethrow on listener failure" mode and return the previous value.
     *
     * TransactionMiddleware enables this just before invoking the handler so
     * that synchronous-listener failures fail the whole command (rolling back
     * the entity write in the same transaction). The previous value is
     * returned so the caller can restore it in a `finally` block — required
     * for nested command dispatches and for not leaking state into tests.
     */
    public function setRethrowOnListenerFailure(bool $rethrow): bool;
}
