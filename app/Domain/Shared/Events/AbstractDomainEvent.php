<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

use Ramsey\Uuid\Uuid;

/**
 * Common base for every domain event in the system.
 *
 * Carries the five-field envelope that the round-3 audit (slice 05/F1)
 * identified as missing from the original Cookie events: a unique
 * `eventId` (UUIDv7 — time-ordered, dedup-friendly), the UTC `occurredAt`
 * timestamp, the optional `actorId` of the user/system that caused the
 * event, and the `aggregateType` + `aggregateId` pair so downstream
 * consumers (audit, outbox relay, read-model projections) don't have to
 * sniff the concrete event class to know which aggregate they belong to.
 *
 * Why a single base class:
 * - **Idempotency anchor.** The transactional outbox relay can dedupe
 *   replays by `eventId`. Without a stable per-event id, every retry
 *   would re-fire side-effect handlers (e.g. notification emails).
 * - **Audit completeness.** A clone of Cookie into a new domain would
 *   otherwise inherit whichever neighbour event the cloner happened to
 *   open first — leading to ragged metadata across the bounded context.
 * - **String-typed aggregateId.** Cookies use `int` PKs today, but
 *   future aggregates may use UUIDs. Stringifying at the envelope layer
 *   keeps the relay/outbox row format stable across all aggregate
 *   identity strategies.
 *
 * Why `readonly`:
 * - Events represent facts that have already happened; their payload
 *   must be immutable.
 *
 * Why `\JsonSerializable`:
 * - Lets the outbox writer and any other serialiser produce a
 *   deterministic on-the-wire shape without leaking PHP-internal
 *   `\DateTimeImmutable` representation. Subclasses extend
 *   {@see self::jsonSerialize()} to merge their own payload via
 *   `array_merge(parent::jsonSerialize(), [...])`.
 *
 * Subclasses MUST:
 * - Be `final readonly`.
 * - Accept the five envelope fields in their constructor and forward
 *   them to `parent::__construct()` (use named arguments for clarity).
 * - Override {@see self::jsonSerialize()} when they add payload fields.
 *
 * @package App\Domain\Shared\Events
 */
abstract readonly class AbstractDomainEvent implements DomainEventInterface, \JsonSerializable
{
    /**
     * Build the envelope. Concrete events accept their own payload as
     * additional constructor parameters and forward the five envelope
     * fields up to this constructor.
     *
     * @param string             $eventId       UUIDv7 envelope id (use {@see self::newId()}).
     * @param \DateTimeImmutable $occurredAt    Event wall-clock time, MUST be UTC.
     * @param int|null           $actorId       Authenticated user id, or null for system-triggered events.
     * @param string             $aggregateType Short aggregate label (e.g. "Cookie", "Invoice").
     * @param string             $aggregateId   Aggregate identifier stringified to support int + UUID PKs.
     */
    public function __construct(
        public string $eventId,
        public \DateTimeImmutable $occurredAt,
        public ?int $actorId,
        public string $aggregateType,
        public string $aggregateId,
    ) {
    }

    /**
     * Generate a fresh UUIDv7 string suitable for the `eventId` envelope
     * field. Centralised so callers do not couple to ramsey/uuid directly.
     *
     * @return string UUIDv7 in canonical 8-4-4-4-12 hexadecimal form.
     */
    protected static function newId(): string
    {
        return Uuid::uuid7()->toString();
    }

    /**
     * Serialise the envelope to a JSON-friendly array.
     *
     * Subclasses override to add their payload, e.g.:
     * ```
     * public function jsonSerialize(): array
     * {
     *     return array_merge(parent::jsonSerialize(), [
     *         'cookieName' => $this->cookieName,
     *         // ...
     *     ]);
     * }
     * ```
     *
     * @return array<string, scalar|array<int|string, scalar|null>|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'actorId' => $this->actorId,
            'aggregateType' => $this->aggregateType,
            'aggregateId' => $this->aggregateId,
        ];
    }

    /**
     * Sibling of {@see self::jsonSerialize()} used by the
     * {@see \App\Infrastructure\Outbox\EventOutboxWriter} which prefers an
     * explicit `toArray()` method over `get_object_vars()` (see writer
     * docblock). Defined separately so the writer continues to produce a
     * clean inner `payload` without DateTimeImmutable noise.
     *
     * @return array<string, scalar|array<int|string, scalar|null>|null>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
