<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

/**
 * Marker interface for command handlers.
 *
 * CommandBus is duck-typed (it only checks `method_exists($handler, 'handle')`)
 * so historical handlers do not need to implement this interface. New
 * handlers SHOULD implement it: the interface documents the contract,
 * lets PHPStan reason about return types, and lets static analysis catch
 * a missing handle() method at the type level instead of at dispatch
 * time.
 *
 * Convention: command handlers return the new aggregate id (int) for
 * `Create*` commands and `void` for everything else. Use `mixed` here
 * because the bus is type-erased — handlers are still free to return
 * a richer type at their own discretion.
 */
interface CommandHandlerInterface
{
    /**
     * Execute the command. Concrete handlers narrow the parameter to
     * their specific command class.
     */
    public function handle(object $command): mixed;
}
