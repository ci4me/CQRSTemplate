<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeleted;

use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\CookieChangeSet;

/**
 * Event fired when a Cookie is soft-deleted.
 *
 * Carries the final state snapshot so an audit consumer can reconstruct
 * the row at the moment of deletion without a follow-up query.
 *
 * Inherits the standard envelope from {@see AbstractDomainEvent}.
 *
 * The snapshot is a typed {@see CookieChangeSet} VO (not a loose array)
 * with a whitelisted field set — see CookieChangeSet for the rationale.
 *
 * @package App\Domain\Cookie\Events\CookieDeleted
 */
final readonly class CookieDeletedEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId    UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt UTC occurrence timestamp.
     * @param int|null           $actorId    Deleting user id, or null for system events.
     * @param int                $cookieId   ID of the deleted cookie.
     * @param string             $cookieName Denormalised name for log readability.
     * @param CookieChangeSet    $snapshot   Whitelisted final state at time of delete.
     */
    public function __construct(
        string $eventId,
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        public int $cookieId,
        public string $cookieName,
        public CookieChangeSet $snapshot,
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
            'cookieName' => $this->cookieName,
            'snapshot' => $this->snapshot->toArray(),
        ]);
    }
}
