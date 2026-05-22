<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Events;

use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\DomainEventInterface;
use Tests\Support\UnitTestCase;

/**
 * Exercises the envelope contract of {@see AbstractDomainEvent}. The tests
 * use an in-file concrete subclass so the base's shape can be verified
 * without coupling to any Cookie-specific event.
 *
 * @package Tests\Unit\Domain\Shared\Events
 */
final class AbstractDomainEventTest extends UnitTestCase
{
    public function test_envelope_exposes_the_five_required_fields(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-05-22T10:00:00+00:00');
        $event = new FakeAbstractEvent(
            eventId: '019e4f88-e80b-7019-aaa7-110cd373192e',
            occurredAt: $occurredAt,
            actorId: 42,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );

        $this->assertSame('019e4f88-e80b-7019-aaa7-110cd373192e', $event->eventId);
        $this->assertSame($occurredAt, $event->occurredAt);
        $this->assertSame(42, $event->actorId);
        $this->assertSame('Cookie', $event->aggregateType);
        $this->assertSame('7', $event->aggregateId);
    }

    public function test_event_implements_domain_event_interface_marker(): void
    {
        $event = FakeAbstractEvent::sample();

        // The relay's rehydrate() refuses to instantiate classes that
        // don't carry this marker. The base must propagate the contract
        // to every concrete subclass.
        $this->assertInstanceOf(DomainEventInterface::class, $event);
    }

    public function test_actor_id_is_nullable_for_system_emitted_events(): void
    {
        $event = new FakeAbstractEvent(
            eventId: FakeAbstractEvent::publicNewId(),
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            actorId: null,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );

        $this->assertNull($event->actorId);
    }

    public function test_new_id_returns_a_valid_uuid_v7_string(): void
    {
        $id = FakeAbstractEvent::publicNewId();

        // UUIDv7 canonical form: 8-4-4-4-12 lowercase hex; version nibble
        // is `7` in the 3rd group, variant nibble is 8/9/a/b in the 4th.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'newId() must return UUIDv7'
        );
    }

    public function test_new_id_returns_a_fresh_uuid_every_call(): void
    {
        // Sanity check: not memoised.
        $this->assertNotSame(FakeAbstractEvent::publicNewId(), FakeAbstractEvent::publicNewId());
    }

    public function test_occurred_at_is_immutable_in_utc(): void
    {
        $utc = new \DateTimeImmutable('2026-05-22 10:00:00', new \DateTimeZone('UTC'));
        $event = new FakeAbstractEvent(
            eventId: FakeAbstractEvent::publicNewId(),
            occurredAt: $utc,
            actorId: null,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );

        // Either "UTC" name or a zero offset is acceptable — both describe
        // the same wall clock. Asserting offset == 0 also catches DST
        // misconfiguration on the host.
        $this->assertSame(0, $event->occurredAt->getOffset());
        $this->assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    public function test_json_serialize_round_trip_emits_the_envelope(): void
    {
        $event = new FakeAbstractEvent(
            eventId: '019e4f88-e80b-7019-aaa7-110cd373192e',
            occurredAt: new \DateTimeImmutable('2026-05-22T10:00:00+00:00'),
            actorId: 42,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );

        $envelope = $event->jsonSerialize();

        $this->assertSame('019e4f88-e80b-7019-aaa7-110cd373192e', $envelope['eventId']);
        $this->assertSame('2026-05-22T10:00:00+00:00', $envelope['occurredAt']);
        $this->assertSame(42, $envelope['actorId']);
        $this->assertSame('Cookie', $envelope['aggregateType']);
        $this->assertSame('7', $envelope['aggregateId']);
    }

    public function test_json_encode_emits_envelope_via_json_serializable_interface(): void
    {
        // Round-trip through json_encode to prove the JsonSerializable
        // implementation kicks in (not just direct method calls).
        $event = new FakeAbstractEvent(
            eventId: '019e4f88-e80b-7019-aaa7-110cd373192e',
            occurredAt: new \DateTimeImmutable('2026-05-22T10:00:00+00:00'),
            actorId: null,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );

        $decoded = json_decode((string) json_encode($event), true);
        $this->assertIsArray($decoded);
        $this->assertSame('019e4f88-e80b-7019-aaa7-110cd373192e', $decoded['eventId']);
        $this->assertNull($decoded['actorId']);
    }

    public function test_to_array_returns_the_same_shape_as_json_serialize(): void
    {
        $event = FakeAbstractEvent::sample();

        $this->assertSame($event->jsonSerialize(), $event->toArray());
    }
}

/**
 * Minimal concrete subclass that exposes the protected `newId()` helper so
 * tests can exercise its return shape without reaching into Cookie events.
 * Lives in the same file because it is purely test scaffolding.
 *
 * @internal
 */
final readonly class FakeAbstractEvent extends AbstractDomainEvent
{
    public static function publicNewId(): string
    {
        return self::newId();
    }

    public static function sample(): self
    {
        return new self(
            eventId: self::newId(),
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            actorId: 42,
            aggregateType: 'Cookie',
            aggregateId: '7',
        );
    }
}
