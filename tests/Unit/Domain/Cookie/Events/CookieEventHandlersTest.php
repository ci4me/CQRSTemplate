<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events;

use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEventHandler;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEventHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEventHandler;
use App\Infrastructure\Logging\LoggerFactory;
use Tests\Support\UnitTestCase;

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
}
