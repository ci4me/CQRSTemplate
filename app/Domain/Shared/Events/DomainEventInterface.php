<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

/**
 * Marker interface every domain event MUST implement.
 *
 * Used by:
 *  - {@see \App\Infrastructure\Bus\EventDispatcher} to type-check listeners
 *    (future improvement — the bus currently accepts any object).
 *  - {@see \App\Infrastructure\Outbox\EventOutboxRelay::rehydrate()} to guard
 *    against arbitrary-class instantiation when a hostile or buggy row in
 *    `event_outbox` carries an unexpected `event_class`.
 *  - {@see \App\Domain\Shared\AggregateRoot} so `raiseEvent()` cannot
 *    accidentally collect non-events (typo, copy/paste).
 *
 * The interface is intentionally empty: the role is purely classifying,
 * not behavioural. Domain events stay plain readonly DTOs (no methods, no
 * coupling to infrastructure).
 */
interface DomainEventInterface
{
}
