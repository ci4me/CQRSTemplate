<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieRestored;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Dispatched after a soft-deleted cookie is brought back from the trash.
 */
final readonly class CookieRestoredEvent extends AbstractDomainEvent
{
    /**
     * Construct a CookieRestoredEvent payload.
     *
     * Envelope fields (eventId/occurredAt/actorId — E04) come from the
     * parent; `restoredBy`/`restoredAt` stay as payload fields for
     * back-compat (occurredAt now carries the authoritative timestamp).
     *
     * @param int    $cookieId   Surrogate id of the row that was just restored.
     * @param int    $restoredBy Actor id that initiated the restore (matches `Actor::$id`).
     * @param string $restoredAt ISO-8601 timestamp of when the UPDATE committed.
     */
    public function __construct(
        public int $cookieId,
        public int $restoredBy,
        public string $restoredAt
    ) {
        parent::__construct(actorId: $restoredBy === 0 ? null : $restoredBy);
    }
}
