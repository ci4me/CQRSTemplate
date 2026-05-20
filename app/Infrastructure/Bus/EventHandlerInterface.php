<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Marker interface for event handlers (listeners).
 *
 * EventDispatcher accepts any callable, but event handlers organised as
 * classes SHOULD implement this interface. The `__invoke` signature with
 * the {@see DomainEventInterface} parameter both documents the contract
 * and gives PHPStan a way to assert that handlers are wired to events
 * that actually exist.
 *
 * Concrete handlers narrow the event type via their own `__invoke`
 * signature; the parent contract uses the broadest acceptable type so
 * the interface composes with handlers from any domain.
 */
interface EventHandlerInterface
{
    /**
     * __invoke.
     *
     * @param DomainEventInterface $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __invoke(DomainEventInterface $event): void;
}
