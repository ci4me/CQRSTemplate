<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieRestored;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Dispatched after a soft-deleted cookie is brought back from the trash.
 *
 * Inherits the standard envelope from {@see AbstractDomainEvent}; the
 * envelope's UTC `occurredAt` is the canonical restore timestamp, so
 * downstream consumers read a single time field across every domain
 * event (round-3 audit slice 05/F3).
 *
 * @package App\Domain\Cookie\Events\CookieRestored
 */
final readonly class CookieRestoredEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId    UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt UTC occurrence timestamp.
     * @param int|null           $actorId    Restoring user id, or null for system events.
     * @param int                $cookieId   ID of the cookie that was restored.
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
