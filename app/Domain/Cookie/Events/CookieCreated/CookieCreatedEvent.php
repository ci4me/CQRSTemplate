<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieCreated;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Event fired when a new Cookie is created.
 *
 * Domain Events represent facts that have happened in the domain.
 * They are:
 * - Named in past tense (CookieCreated, not CreateCookie)
 * - Immutable (you can't change history)
 * - Contain only essential data about what happened
 *
 * Inherits the standard envelope from {@see AbstractDomainEvent}:
 *  - eventId       — UUIDv7 idempotency anchor
 *  - occurredAt    — UTC \DateTimeImmutable
 *  - actorId       — nullable creator user id (system events pass null)
 *  - aggregateType — always "Cookie" for this event
 *  - aggregateId   — the created cookie's id (stringified)
 *
 * Why Domain Events:
 * - Enable loose coupling between bounded contexts
 * - Provide audit trail of what happened
 * - Allow side effects without tight coupling
 * - Foundation for event sourcing if needed
 *
 * Use Cases:
 * - Log cookie creation for audit
 * - Send notification to inventory system
 * - Update analytics/metrics
 * - Trigger cache invalidation
 *
 * @package App\Domain\Cookie\Events\CookieCreated
 */
final readonly class CookieCreatedEvent extends AbstractDomainEvent
{
    /**
     * Create a new CookieCreatedEvent.
     *
     * @param string             $eventId      UUIDv7 envelope id (use {@see AbstractDomainEvent::newId()}).
     * @param \DateTimeImmutable $occurredAt   UTC occurrence timestamp.
     * @param int|null           $actorId      Authenticated user id, or null for system events.
     * @param int                $cookieId     The ID of the created cookie.
     * @param string             $cookieName   The name of the created cookie.
     * @param string             $cookiePrice  Decimal price string for the created cookie.
     * @param int                $initialStock The initial stock quantity.
     */
    public function __construct(
        string $eventId,
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        public int $cookieId,
        public string $cookieName,
        public string $cookiePrice,
        public int $initialStock,
    ) {
        parent::__construct(
            eventId: $eventId,
            occurredAt: $occurredAt,
            actorId: $actorId,
            aggregateType: 'Cookie',
            aggregateId: (string) $cookieId,
        );
    }

    /**
     * Add the Cookie-specific payload on top of the envelope.
     *
     * @return array<string, scalar|array<int|string, scalar|null>|null>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'cookieId' => $this->cookieId,
            'cookieName' => $this->cookieName,
            'cookiePrice' => $this->cookiePrice,
            'initialStock' => $this->initialStock,
        ]);
    }
}
