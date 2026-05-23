<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieRestored;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Dispatched after a soft-deleted cookie is brought back from the trash.
 */
final readonly class CookieRestoredEvent implements DomainEventInterface
{
    /**
     * Construct a CookieRestoredEvent payload.
     *
     * Carries the bare scalar fields downstream consumers need to react to the
     * restore without re-querying the row. `restoredAt` is an ISO-8601 string
     * (RFC 3339 / `DateTimeImmutable::format('c')`) so the payload stays
     * serialisable through the outbox.
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
    }
}
