<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events;

use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\StockChangeReason;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\CookieChangeSet;
use App\Domain\Shared\Events\DomainEventInterface;
use Tests\Support\UnitTestCase;

/**
 * Per-event shape tests for the five Cookie domain events. The envelope
 * contract itself (eventId/occurredAt/actorId/aggregateType/aggregateId)
 * is exercised in {@see \Tests\Unit\Domain\Shared\Events\AbstractDomainEventTest};
 * here we focus on Cookie-specific payload + the few invariants the
 * round-3 audit called out (non-nullable cookieId on stock-changed, no
 * `restoredAt` on restored, typed CookieChangeSet on updated/deleted).
 *
 * @package Tests\Unit\Domain\Cookie\Events
 */
final class CookieEventsTest extends UnitTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-05-22 10:00:00', new \DateTimeZone('UTC'));
    }

    // ==========================================
    // Shared envelope assertions
    // ==========================================

    public function test_every_cookie_event_extends_abstract_domain_event(): void
    {
        // Regression guard: a future contributor adding a Cookie event
        // must inherit the envelope, not start a new shape.
        $events = [
            $this->makeCreated(),
            $this->makeUpdated(),
            $this->makeDeleted(),
            $this->makeRestored(),
            $this->makeStockChanged(),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(AbstractDomainEvent::class, $event);
            $this->assertInstanceOf(DomainEventInterface::class, $event);
            $this->assertSame('Cookie', $event->aggregateType);
        }
    }

    // ==========================================
    // CookieCreatedEvent
    // ==========================================

    public function test_cookie_created_event_carries_envelope_and_payload(): void
    {
        $event = new CookieCreatedEvent(
            eventId: 'evt-1',
            occurredAt: $this->now,
            actorId: 42,
            cookieId: 1,
            cookieName: 'Chocolate Chip',
            cookiePrice: '2.99',
            initialStock: 100,
        );

        $this->assertSame('evt-1', $event->eventId);
        $this->assertSame(42, $event->actorId);
        $this->assertSame('Cookie', $event->aggregateType);
        $this->assertSame('1', $event->aggregateId);
        $this->assertSame(1, $event->cookieId);
        $this->assertSame('Chocolate Chip', $event->cookieName);
        $this->assertSame('2.99', $event->cookiePrice);
        $this->assertSame(100, $event->initialStock);
    }

    public function test_cookie_created_event_json_serialize_merges_envelope_and_payload(): void
    {
        $event = new CookieCreatedEvent(
            eventId: 'evt-1',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'Test Cookie',
            cookiePrice: '1.99',
            initialStock: 50,
        );

        $decoded = $event->jsonSerialize();

        $this->assertSame('evt-1', $decoded['eventId']);
        $this->assertNull($decoded['actorId']);
        $this->assertSame(1, $decoded['cookieId']);
        $this->assertSame('Test Cookie', $decoded['cookieName']);
        $this->assertSame(50, $decoded['initialStock']);
    }

    public function test_cookie_created_event_handles_zero_and_high_stock(): void
    {
        $zero = new CookieCreatedEvent(
            eventId: 'a',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'Out of Stock',
            cookiePrice: '2.99',
            initialStock: 0,
        );
        $high = new CookieCreatedEvent(
            eventId: 'b',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'Popular',
            cookiePrice: '2.99',
            initialStock: 999_999,
        );

        $this->assertSame(0, $zero->initialStock);
        $this->assertSame(999_999, $high->initialStock);
    }

    // ==========================================
    // CookieUpdatedEvent
    // ==========================================

    public function test_cookie_updated_event_carries_typed_change_sets(): void
    {
        $previous = CookieChangeSet::fromArray(['name' => 'Old', 'price_minor' => 199]);
        $new = CookieChangeSet::fromArray(['name' => 'New', 'price_minor' => 299]);

        $event = new CookieUpdatedEvent(
            eventId: 'evt-2',
            occurredAt: $this->now,
            actorId: 7,
            cookieId: 123,
            cookieName: 'New',
            cookiePrice: '2.99',
            previousState: $previous,
            newState: $new,
        );

        $this->assertSame(123, $event->cookieId);
        $this->assertSame('123', $event->aggregateId);
        $this->assertSame($previous, $event->previousState);
        $this->assertSame($new, $event->newState);
        $this->assertInstanceOf(CookieChangeSet::class, $event->previousState);
        $this->assertInstanceOf(CookieChangeSet::class, $event->newState);
    }

    public function test_cookie_updated_event_json_serialize_unwraps_change_sets(): void
    {
        $event = $this->makeUpdated();
        $decoded = $event->jsonSerialize();

        $this->assertIsArray($decoded['previousState']);
        $this->assertIsArray($decoded['newState']);
        $this->assertSame(['name' => 'Old'], $decoded['previousState']);
        $this->assertSame(['name' => 'New'], $decoded['newState']);
    }

    // ==========================================
    // CookieDeletedEvent
    // ==========================================

    public function test_cookie_deleted_event_carries_typed_snapshot(): void
    {
        $snapshot = CookieChangeSet::fromArray([
            'id' => 789,
            'name' => 'Discontinued',
            'price_minor' => 299,
            'stock' => 0,
            'is_active' => false,
        ]);

        $event = new CookieDeletedEvent(
            eventId: 'evt-3',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 789,
            cookieName: 'Discontinued',
            snapshot: $snapshot,
        );

        $this->assertSame(789, $event->cookieId);
        $this->assertSame('Discontinued', $event->cookieName);
        $this->assertSame($snapshot, $event->snapshot);
        $this->assertInstanceOf(CookieChangeSet::class, $event->snapshot);
    }

    // ==========================================
    // CookieRestoredEvent
    // ==========================================

    public function test_cookie_restored_event_no_longer_carries_restored_at(): void
    {
        $event = $this->makeRestored();

        // Regression guard: the legacy string `restoredAt` field is gone,
        // superseded by the envelope's `occurredAt`.
        $this->assertObjectNotHasProperty('restoredAt', $event);
        $this->assertObjectNotHasProperty('restoredBy', $event);
        $this->assertSame(0, $event->occurredAt->getOffset());
    }

    // ==========================================
    // CookieStockChangedEvent
    // ==========================================

    public function test_cookie_stock_changed_event_cookie_id_is_non_nullable(): void
    {
        // Compile-time non-nullable: a reflection assertion catches an
        // accidental regression back to `?int`.
        $reflected = new \ReflectionClass(CookieStockChangedEvent::class);
        $cookieIdProperty = $reflected->getProperty('cookieId');
        $type = $cookieIdProperty->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
        $this->assertFalse($type->allowsNull(), 'cookieId must be non-nullable');
    }

    public function test_cookie_stock_changed_event_payload_round_trip(): void
    {
        $event = new CookieStockChangedEvent(
            eventId: 'evt-4',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 5,
            previousStock: 100,
            newStock: 99,
            reason: StockChangeReason::Sale,
        );

        $this->assertSame(5, $event->cookieId);
        $this->assertSame(100, $event->previousStock);
        $this->assertSame(99, $event->newStock);
        $this->assertSame(StockChangeReason::Sale, $event->reason);
        $this->assertSame('SALE', $event->jsonSerialize()['reason'], 'wire payload uses enum backing value');
    }

    // ==========================================
    // Fixtures
    // ==========================================

    private function makeCreated(): CookieCreatedEvent
    {
        return new CookieCreatedEvent(
            eventId: 'fixture',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'X',
            cookiePrice: '1.00',
            initialStock: 1,
        );
    }

    private function makeUpdated(): CookieUpdatedEvent
    {
        return new CookieUpdatedEvent(
            eventId: 'fixture',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'New',
            cookiePrice: '2.99',
            previousState: CookieChangeSet::fromArray(['name' => 'Old']),
            newState: CookieChangeSet::fromArray(['name' => 'New']),
        );
    }

    private function makeDeleted(): CookieDeletedEvent
    {
        return new CookieDeletedEvent(
            eventId: 'fixture',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'X',
            snapshot: CookieChangeSet::empty(),
        );
    }

    private function makeRestored(): CookieRestoredEvent
    {
        return new CookieRestoredEvent(
            eventId: 'fixture',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
        );
    }

    private function makeStockChanged(): CookieStockChangedEvent
    {
        return new CookieStockChangedEvent(
            eventId: 'fixture',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            previousStock: 10,
            newStock: 9,
            reason: StockChangeReason::Sale,
        );
    }
}
