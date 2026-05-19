<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Trait shared by every aggregate root in the domain layer.
 *
 * An aggregate root accumulates domain events during its lifecycle (state
 * transitions raise an event but DO NOT dispatch it). The repository drains
 * the events when persistence succeeds and the bus relays them — keeping
 * the entity ignorant of the dispatcher and making transactional outbox
 * patterns easy to bolt on later.
 *
 * Usage in an entity:
 * ```
 * final class Cookie
 * {
 *     use AggregateRoot;
 *
 *     public function decreaseStock(int $qty): void
 *     {
 *         // ... invariant checks ...
 *         $this->stock -= $qty;
 *         $this->raiseEvent(new StockDecreased($this->id, $qty));
 *     }
 * }
 * ```
 *
 * Usage in a repository (after a successful save):
 * ```
 * foreach ($cookie->pullEvents() as $event) {
 *     $this->dispatcher->dispatch($event);
 * }
 * ```
 *
 * The pull-then-clear pattern guarantees events are emitted at most once:
 * calling pullEvents twice yields the same set the first time and an empty
 * list the second.
 */
trait AggregateRoot
{
    /**
     * @var list<object>
     */
    private array $pendingEvents = [];

    /**
     * Queue a domain event for later dispatch.
     *
     * Events are NOT dispatched immediately — they accumulate until the
     * repository (or an explicit caller) drains them via pullEvents().
     */
    protected function raiseEvent(object $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /**
     * Return the queued events and clear the buffer.
     *
     * @return list<object>
     */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /**
     * Inspect queued events without clearing the buffer. Useful in tests.
     *
     * @return list<object>
     */
    public function peekEvents(): array
    {
        return $this->pendingEvents;
    }

    public function hasPendingEvents(): bool
    {
        return $this->pendingEvents !== [];
    }
}
