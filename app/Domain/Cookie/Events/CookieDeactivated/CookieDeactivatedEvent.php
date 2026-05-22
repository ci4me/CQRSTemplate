<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeactivated;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Dispatched when an active cookie is flipped to inactive.
 *
 * Counterpart to {@see \App\Domain\Cookie\Events\CookieActivated\CookieActivatedEvent}.
 * See its docblock for the full lifecycle-event rationale (round-3 audit
 * slice 01/F2). Inactive cookies must not be displayed to customers but
 * remain in the catalog for re-activation.
 *
 * @package App\Domain\Cookie\Events\CookieDeactivated
 */
final readonly class CookieDeactivatedEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId    UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt UTC occurrence timestamp.
     * @param int|null           $actorId    Deactivating user id, or null for system events.
     * @param int                $cookieId   ID of the deactivated cookie.
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
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'cookieId' => $this->cookieId,
        ]);
    }
}
