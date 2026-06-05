<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeleted;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Event fired when a Cookie is soft-deleted.
 *
 * Carries the full final snapshot so an audit consumer can reconstruct the
 * row at the moment of deletion without a follow-up query.
 *
 * @package App\Domain\Cookie\Events\CookieDeleted
 */
final readonly class CookieDeletedEvent extends AbstractDomainEvent
{
    /**
     * Envelope fields (eventId/occurredAt/actorId — E04) come from the
     * parent; `deletedBy` is kept as a payload field for back-compat and
     * doubles as the envelope actorId.
     *
     * @param int                        $cookieId   ID of the deleted cookie
     * @param string                     $cookieName Denormalised name for log readability
     * @param array<string, scalar|null> $snapshot   Final state at time of delete
     * @param int                        $deletedBy  Actor id (0 for system)
     */
    public function __construct(
        public int $cookieId,
        public string $cookieName,
        public array $snapshot = [],
        public int $deletedBy = 0
    ) {
        parent::__construct(actorId: $deletedBy === 0 ? null : $deletedBy);
    }
}
