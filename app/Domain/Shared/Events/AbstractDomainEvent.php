<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

/**
 * Base envelope every domain event extends (E04, landed in round-4 R2).
 *
 * Provides the three audit-trail fields ERP consumers need on EVERY event:
 *
 *  - `eventId`    — UUIDv4 minted at construction. Stable across outbox
 *                   serialisation, so downstream consumers can deduplicate
 *                   (E12 adds a UNIQUE `event_uuid` column keyed on it).
 *  - `occurredAt` — RFC 3339 timestamp of the moment the event was raised
 *                   (not when it was dispatched or relayed).
 *  - `actorId`    — who caused the change (`Actor::$id`), null for system.
 *
 * Subclasses stay plain readonly DTOs: they declare their payload fields via
 * constructor promotion and call `parent::__construct(actorId: ...)`. The
 * envelope fields are deliberately NOT part of each subclass's positional
 * signature so existing call sites keep working.
 *
 * JsonSerializable: events serialise to a flat array of their public state
 * (envelope + payload), which is also what the outbox writer stores as the
 * inner payload body.
 */
abstract readonly class AbstractDomainEvent implements DomainEventInterface, \JsonSerializable
{
    public string $eventId;
    public string $occurredAt;
    public ?int $actorId;

    /**
     * Mint the envelope. Subclasses call this from their promoted-property
     * constructors; all three fields are optional so reconstruction paths
     * (outbox relay, tests) can supply recorded values.
     *
     * @param string|null $eventId    Existing UUID when rehydrating; null mints a new UUIDv4
     * @param string|null $occurredAt Existing RFC 3339 timestamp; null stamps "now"
     * @param int|null    $actorId    Actor id that caused the change; null for system
     */
    protected function __construct(?string $eventId = null, ?string $occurredAt = null, ?int $actorId = null)
    {
        $this->eventId = $eventId ?? self::mintUuidV4();
        $this->occurredAt = $occurredAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->actorId = $actorId;
    }

    /**
     * Flat wire shape: envelope fields + the subclass's payload fields.
     *
     * @return array<string, array<int|string, scalar|array<int|string, scalar|null>|null>|scalar|null>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * RFC 4122 version-4 UUID from the CSPRNG — no library dependency.
     */
    private static function mintUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
