<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieActivated;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Dispatched when a previously-inactive cookie is flipped back to active.
 *
 * Active/inactive transitions are exactly the kind of business state
 * change downstream consumers (catalog, search index, low-stock alerts)
 * need to react to. Pre-E07 the entity flipped `$isActive` silently —
 * round-3 audit slice 01/F2 flagged the gap. The event carries only the
 * envelope (id / timestamp / actor / aggregate type+id); the new state
 * is implicit in the event class.
 *
 * @package App\Domain\Cookie\Events\CookieActivated
 */
final readonly class CookieActivatedEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId    UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt UTC occurrence timestamp.
     * @param int|null           $actorId    Activating user id, or null for system events.
     * @param int                $cookieId   ID of the activated cookie.
     */
    public function __construct(
        string $eventId,
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        public int $cookieId,
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
     * @return array<string, scalar|array<int|string, scalar|null>|null>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'cookieId' => $this->cookieId,
        ]);
    }
}
