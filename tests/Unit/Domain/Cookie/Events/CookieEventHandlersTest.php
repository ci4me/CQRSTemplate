<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events;

use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEventHandler;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEventHandler;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEventHandler;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEventHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEventHandler;
use App\Domain\Cookie\ValueObjects\StockChangeReason;
use App\Domain\Shared\Events\CookieChangeSet;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class CookieEventHandlersTest extends UnitTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-05-22 10:00:00', new \DateTimeZone('UTC'));
    }

    // ==========================================
    // CookieCreatedEventHandler Tests
    // ==========================================

    public function test_cookie_created_handler_can_be_instantiated(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieCreatedEventHandler($logger);

        $this->assertInstanceOf(CookieCreatedEventHandler::class, $handler);
    }

    public function test_cookie_created_handler_handles_event_without_error(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            eventId: 'evt-created-1',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'Test Cookie',
            cookiePrice: '2.99',
            initialStock: 100,
        );

        // Should not throw any exceptions
        $handler($event);

        $this->assertTrue(true); // Assert we reached here without exception
    }

    public function test_cookie_created_handler_handles_zero_stock(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            eventId: 'evt-created-2',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            cookieName: 'Out of Stock',
            cookiePrice: '2.99',
            initialStock: 0,
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_created_handler_handles_special_characters_in_name(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            eventId: 'evt-created-3',
            occurredAt: $this->now,
            actorId: 7,
            cookieId: 1,
            cookieName: 'Cookie "Special" & Characters!',
            cookiePrice: '2.99',
            initialStock: 50,
        );

        $handler($event);

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieUpdatedEventHandler Tests
    // ==========================================

    public function test_cookie_updated_handler_can_be_instantiated(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieUpdatedEventHandler($logger);

        $this->assertInstanceOf(CookieUpdatedEventHandler::class, $handler);
    }

    public function test_cookie_updated_handler_handles_event_without_error(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieUpdatedEventHandler($logger);
        $event = $this->makeUpdatedEvent(cookieId: 1, name: 'Updated Cookie', price: '3.99');

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_updated_handler_handles_price_change(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieUpdatedEventHandler($logger);
        $event = $this->makeUpdatedEvent(cookieId: 5, name: 'Premium Cookie', price: '9.99');

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_updated_handler_handles_special_characters(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieUpdatedEventHandler($logger);
        $event = $this->makeUpdatedEvent(cookieId: 1, name: 'Renamed "Cookie" & More!', price: '2.99');

        $handler($event);

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieDeletedEventHandler Tests
    // ==========================================

    public function test_cookie_deleted_handler_can_be_instantiated(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieDeletedEventHandler($logger);

        $this->assertInstanceOf(CookieDeletedEventHandler::class, $handler);
    }

    public function test_cookie_deleted_handler_handles_event_without_error(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieDeletedEventHandler($logger);
        $event = $this->makeDeletedEvent(cookieId: 1, name: 'Deleted Cookie');

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_deleted_handler_handles_special_characters(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieDeletedEventHandler($logger);
        $event = $this->makeDeletedEvent(cookieId: 999, name: 'Cookie with "Quotes" & Symbols!');

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_deleted_handler_handles_long_name(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieDeletedEventHandler($logger);
        $longName = str_repeat('A', 255);
        $event = $this->makeDeletedEvent(cookieId: 1, name: $longName);

        $handler($event);

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieRestoredEventHandler Tests
    // ==========================================

    public function test_cookie_restored_handler_logs_with_audit_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Cookie restored', $this->callback(function (array $ctx): bool {
                // The legacy `restored_at` string field is gone — the
                // handler now logs the envelope's `occurredAt`.
                return $ctx['domain'] === 'Cookie'
                    && $ctx['event'] === 'CookieRestoredEvent'
                    && $ctx['event_id'] === 'evt-restored-1'
                    && $ctx['cookie_id'] === 7
                    && $ctx['restored_by'] === 42
                    && $ctx['occurred_at'] === '2026-05-22T10:00:00+00:00';
            }));

        (new CookieRestoredEventHandler($logger))(new CookieRestoredEvent(
            eventId: 'evt-restored-1',
            occurredAt: $this->now,
            actorId: 42,
            cookieId: 7,
        ));
    }

    public function test_cookie_restored_handler_does_not_throw_with_real_logger(): void
    {
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieRestoredEventHandler($logger);

        $handler(new CookieRestoredEvent(
            eventId: 'evt-restored-2',
            occurredAt: $this->now,
            actorId: 1,
            cookieId: 1,
        ));

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieStockChangedEventHandler Tests
    // ==========================================

    public function test_cookie_stock_changed_handler_logs_movement_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Cookie stock changed', $this->callback(function (array $ctx): bool {
                return $ctx['domain'] === 'Cookie'
                    && $ctx['event'] === 'CookieStockChangedEvent'
                    && $ctx['event_id'] === 'evt-stock-1'
                    && $ctx['cookie_id'] === 5
                    && $ctx['previous_stock'] === 100
                    && $ctx['new_stock'] === 99
                    && $ctx['reason'] === 'SALE';
            }));

        (new CookieStockChangedEventHandler($logger))(new CookieStockChangedEvent(
            eventId: 'evt-stock-1',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 5,
            previousStock: 100,
            newStock: 99,
            reason: StockChangeReason::Sale,
        ));
    }

    public function test_cookie_stock_changed_handler_accepts_persisted_cookie_id(): void
    {
        // Round-3 audit 05/F2: cookieId is now non-nullable. Stock cannot
        // move on an unpersisted aggregate, so the previous nullable type
        // was a lie. This test pins the new contract.
        $logger = new Logger('test.cookie.events', [new TestHandler()]);
        $handler = new CookieStockChangedEventHandler($logger);

        $handler(new CookieStockChangedEvent(
            eventId: 'evt-stock-2',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 1,
            previousStock: 0,
            newStock: 50,
            reason: StockChangeReason::InitialStock,
        ));

        $this->assertTrue(true);
    }

    private function makeUpdatedEvent(int $cookieId, string $name, string $price): CookieUpdatedEvent
    {
        return new CookieUpdatedEvent(
            eventId: 'evt-updated-' . $cookieId,
            occurredAt: $this->now,
            actorId: null,
            cookieId: $cookieId,
            cookieName: $name,
            cookiePrice: $price,
            previousState: CookieChangeSet::empty(),
            newState: CookieChangeSet::empty(),
        );
    }

    private function makeDeletedEvent(int $cookieId, string $name): CookieDeletedEvent
    {
        return new CookieDeletedEvent(
            eventId: 'evt-deleted-' . $cookieId,
            occurredAt: $this->now,
            actorId: null,
            cookieId: $cookieId,
            cookieName: $name,
            snapshot: CookieChangeSet::empty(),
        );
    }
}
