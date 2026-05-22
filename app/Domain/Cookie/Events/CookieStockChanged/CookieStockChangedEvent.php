<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieStockChanged;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Raised when a cookie's stock level changes via a domain operation
 * (decrease/increase). Allows downstream consumers — inventory
 * dashboards, low-stock alerts, replenishment — to react without polling.
 *
 * NOT raised by the bulk-replace path used by UpdateCookieCommand; that
 * already emits CookieUpdatedEvent with previous/new state, which is the
 * higher-resolution signal.
 *
 * Inherits the standard envelope from {@see AbstractDomainEvent}.
 * `cookieId` is non-nullable: stock cannot change on an unpersisted
 * aggregate, so the previous `?int` type was a lie (slice 05/F2). The
 * entity guards this via `assertPersisted()` before raising.
 *
 * @package App\Domain\Cookie\Events\CookieStockChanged
 */
final readonly class CookieStockChangedEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId       UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt    UTC occurrence timestamp.
     * @param int|null           $actorId       Acting user id, or null for system events.
     * @param int                $cookieId      Persisted cookie id (non-nullable: cannot move stock on a transient aggregate).
     * @param int                $previousStock Stock level before this movement.
     * @param int                $newStock      Stock level after this movement.
     * @param string             $reason        Free-form reason label (e.g. "decreaseStock", "increaseStock").
     */
    public function __construct(
        string $eventId,
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        public int $cookieId,
        public int $previousStock,
        public int $newStock,
        public string $reason,
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
            'previousStock' => $this->previousStock,
            'newStock' => $this->newStock,
            'reason' => $this->reason,
        ]);
    }
}
