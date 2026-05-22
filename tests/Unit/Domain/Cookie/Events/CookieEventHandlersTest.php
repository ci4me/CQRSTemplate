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
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class CookieEventHandlersTest extends UnitTestCase
{
    // ==========================================
    // CookieCreatedEventHandler Tests
    // ==========================================

    public function test_cookie_created_handler_can_be_instantiated(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieCreatedEventHandler($logger);

        $this->assertInstanceOf(CookieCreatedEventHandler::class, $handler);
    }

    public function test_cookie_created_handler_handles_event_without_error(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Test Cookie',
            cookiePrice: '2.99',
            initialStock: 100
        );

        // Should not throw any exceptions
        $handler($event);

        $this->assertTrue(true); // Assert we reached here without exception
    }

    public function test_cookie_created_handler_handles_zero_stock(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Out of Stock',
            cookiePrice: '2.99',
            initialStock: 0
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_created_handler_handles_special_characters_in_name(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieCreatedEventHandler($logger);
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Cookie "Special" & Characters!',
            cookiePrice: '2.99',
            initialStock: 50
        );

        $handler($event);

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieUpdatedEventHandler Tests
    // ==========================================

    public function test_cookie_updated_handler_can_be_instantiated(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieUpdatedEventHandler($logger);

        $this->assertInstanceOf(CookieUpdatedEventHandler::class, $handler);
    }

    public function test_cookie_updated_handler_handles_event_without_error(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieUpdatedEventHandler($logger);
        $event = new CookieUpdatedEvent(
            cookieId: 1,
            cookieName: 'Updated Cookie',
            cookiePrice: '3.99'
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_updated_handler_handles_price_change(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieUpdatedEventHandler($logger);
        $event = new CookieUpdatedEvent(
            cookieId: 5,
            cookieName: 'Premium Cookie',
            cookiePrice: '9.99'
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_updated_handler_handles_special_characters(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieUpdatedEventHandler($logger);
        $event = new CookieUpdatedEvent(
            cookieId: 1,
            cookieName: 'Renamed "Cookie" & More!',
            cookiePrice: '2.99'
        );

        $handler($event);

        $this->assertTrue(true);
    }

    // ==========================================
    // CookieDeletedEventHandler Tests
    // ==========================================

    public function test_cookie_deleted_handler_can_be_instantiated(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieDeletedEventHandler($logger);

        $this->assertInstanceOf(CookieDeletedEventHandler::class, $handler);
    }

    public function test_cookie_deleted_handler_handles_event_without_error(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieDeletedEventHandler($logger);
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: 'Deleted Cookie'
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_deleted_handler_handles_special_characters(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieDeletedEventHandler($logger);
        $event = new CookieDeletedEvent(
            cookieId: 999,
            cookieName: 'Cookie with "Quotes" & Symbols!'
        );

        $handler($event);

        $this->assertTrue(true);
    }

    public function test_cookie_deleted_handler_handles_long_name(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieDeletedEventHandler($logger);
        $longName = str_repeat('A', 255);
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: $longName
        );

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
                return $ctx['domain'] === 'Cookie'
                    && $ctx['event'] === 'CookieRestoredEvent'
                    && $ctx['cookie_id'] === 7
                    && $ctx['restored_by'] === 42
                    && $ctx['restored_at'] === '2026-05-22 10:00:00';
            }));

        (new CookieRestoredEventHandler($logger))(new CookieRestoredEvent(
            cookieId: 7,
            restoredBy: 42,
            restoredAt: '2026-05-22 10:00:00'
        ));
    }

    public function test_cookie_restored_handler_does_not_throw_with_real_logger(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieRestoredEventHandler($logger);

        $handler(new CookieRestoredEvent(
            cookieId: 1,
            restoredBy: 1,
            restoredAt: '2026-01-01 00:00:00'
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
                    && $ctx['cookie_id'] === 5
                    && $ctx['previous_stock'] === 100
                    && $ctx['new_stock'] === 99
                    && $ctx['reason'] === 'sale';
            }));

        (new CookieStockChangedEventHandler($logger))(new CookieStockChangedEvent(
            cookieId: 5,
            previousStock: 100,
            newStock: 99,
            reason: 'sale'
        ));
    }

    public function test_cookie_stock_changed_handler_allows_null_cookie_id(): void
    {
        // cookieId is nullable on the event for pre-persistence stock changes.
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieStockChangedEventHandler($logger);

        $handler(new CookieStockChangedEvent(
            cookieId: null,
            previousStock: 0,
            newStock: 50,
            reason: 'initial_load'
        ));

        $this->assertTrue(true);
    }
}
