<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieUpdated;

use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\CookieChangeSet;

/**
 * Event fired when a Cookie is updated.
 *
 * Carries both the previous and the new public state so audit consumers
 * can record a structured diff without needing to query the database.
 *
 * Inherits the standard envelope from {@see AbstractDomainEvent}.
 *
 * The state snapshots are typed `{@see CookieChangeSet}` value objects
 * — not loose `array<string, scalar|null>` — so the field set is
 * whitelisted and unknown keys throw at construction time (round-3
 * audit 05/F4). This keeps PII leakage out of `event_outbox` if the
 * Cookie template is cloned to a domain with personal data.
 *
 * @package App\Domain\Cookie\Events\CookieUpdated
 */
final readonly class CookieUpdatedEvent extends AbstractDomainEvent
{
    /**
     * @param string             $eventId       UUIDv7 envelope id.
     * @param \DateTimeImmutable $occurredAt    UTC occurrence timestamp.
     * @param int|null           $actorId       Updating user id, or null for system events.
     * @param int                $cookieId      ID of the updated cookie.
     * @param string             $cookieName    New name (denormalised for log readability).
     * @param string             $cookiePrice   New decimal price string.
     * @param CookieChangeSet    $previousState Whitelisted snapshot before the update.
     * @param CookieChangeSet    $newState      Whitelisted snapshot after the update.
     */
    public function __construct(
        string $eventId,
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        public int $cookieId,
        public string $cookieName,
        public string $cookiePrice,
        public CookieChangeSet $previousState,
        public CookieChangeSet $newState,
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
            'cookieName' => $this->cookieName,
            'cookiePrice' => $this->cookiePrice,
            'previousState' => $this->previousState->toArray(),
            'newState' => $this->newState->toArray(),
        ]);
    }
}
