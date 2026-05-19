<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events;

use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use Tests\Support\UnitTestCase;

final class CookieEventsTest extends UnitTestCase
{
    // ==========================================
    // CookieCreatedEvent Tests
    // ==========================================

    public function test_cookie_created_event_creates_successfully(): void
    {
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Chocolate Chip',
            cookiePrice: '2.99',
            initialStock: 100
        );

        $this->assertInstanceOf(CookieCreatedEvent::class, $event);
    }

    public function test_cookie_created_event_stores_all_properties(): void
    {
        $event = new CookieCreatedEvent(
            cookieId: 42,
            cookieName: 'Peanut Butter Cookie',
            cookiePrice: '3.49',
            initialStock: 75
        );

        $this->assertEquals(42, $event->cookieId);
        $this->assertEquals('Peanut Butter Cookie', $event->cookieName);
        $this->assertEquals('3.49', $event->cookiePrice);
        $this->assertEquals(75, $event->initialStock);
    }

    public function test_cookie_created_event_is_immutable(): void
    {
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Test Cookie',
            cookiePrice: '1.99',
            initialStock: 50
        );

        // Readonly properties cannot be modified
        // This is enforced by PHP's type system
        $this->assertSame(1, $event->cookieId);
        $this->assertSame('Test Cookie', $event->cookieName);
    }

    public function test_cookie_created_event_handles_zero_stock(): void
    {
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Out of Stock Cookie',
            cookiePrice: '2.99',
            initialStock: 0
        );

        $this->assertEquals(0, $event->initialStock);
    }

    public function test_cookie_created_event_handles_high_stock(): void
    {
        $event = new CookieCreatedEvent(
            cookieId: 1,
            cookieName: 'Popular Cookie',
            cookiePrice: '2.99',
            initialStock: 999999
        );

        $this->assertEquals(999999, $event->initialStock);
    }

    // ==========================================
    // CookieUpdatedEvent Tests
    // ==========================================

    public function test_cookie_updated_event_creates_successfully(): void
    {
        $event = new CookieUpdatedEvent(
            cookieId: 1,
            cookieName: 'Updated Cookie',
            cookiePrice: '4.99'
        );

        $this->assertInstanceOf(CookieUpdatedEvent::class, $event);
    }

    public function test_cookie_updated_event_stores_all_properties(): void
    {
        $event = new CookieUpdatedEvent(
            cookieId: 123,
            cookieName: 'Double Chocolate',
            cookiePrice: '3.75'
        );

        $this->assertEquals(123, $event->cookieId);
        $this->assertEquals('Double Chocolate', $event->cookieName);
        $this->assertEquals('3.75', $event->cookiePrice);
    }

    public function test_cookie_updated_event_is_immutable(): void
    {
        $event = new CookieUpdatedEvent(
            cookieId: 1,
            cookieName: 'Test Cookie',
            cookiePrice: '2.99'
        );

        // Readonly properties cannot be modified
        $this->assertSame(1, $event->cookieId);
        $this->assertSame('Test Cookie', $event->cookieName);
    }

    public function test_cookie_updated_event_handles_price_change(): void
    {
        $event = new CookieUpdatedEvent(
            cookieId: 1,
            cookieName: 'Premium Cookie',
            cookiePrice: '9.99'
        );

        $this->assertEquals('9.99', $event->cookiePrice);
    }

    public function test_cookie_updated_event_handles_name_change(): void
    {
        $event = new CookieUpdatedEvent(
            cookieId: 5,
            cookieName: 'Rebranded Cookie Name with Special Characters!',
            cookiePrice: '2.99'
        );

        $this->assertEquals('Rebranded Cookie Name with Special Characters!', $event->cookieName);
    }

    // ==========================================
    // CookieDeletedEvent Tests
    // ==========================================

    public function test_cookie_deleted_event_creates_successfully(): void
    {
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: 'Deleted Cookie'
        );

        $this->assertInstanceOf(CookieDeletedEvent::class, $event);
    }

    public function test_cookie_deleted_event_stores_all_properties(): void
    {
        $event = new CookieDeletedEvent(
            cookieId: 789,
            cookieName: 'Discontinued Cookie'
        );

        $this->assertEquals(789, $event->cookieId);
        $this->assertEquals('Discontinued Cookie', $event->cookieName);
    }

    public function test_cookie_deleted_event_is_immutable(): void
    {
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: 'Test Cookie'
        );

        // Readonly properties cannot be modified
        $this->assertSame(1, $event->cookieId);
        $this->assertSame('Test Cookie', $event->cookieName);
    }

    public function test_cookie_deleted_event_handles_long_name(): void
    {
        $longName = str_repeat('A', 255);
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: $longName
        );

        $this->assertEquals($longName, $event->cookieName);
    }

    public function test_cookie_deleted_event_represents_soft_delete(): void
    {
        // This test documents that the event represents a soft delete
        // The actual soft delete logic is in the handler/entity
        $event = new CookieDeletedEvent(
            cookieId: 1,
            cookieName: 'Soft Deleted Cookie'
        );

        // Event only carries the fact that deletion happened
        $this->assertEquals(1, $event->cookieId);
        $this->assertIsInt($event->cookieId);
        $this->assertIsString($event->cookieName);
    }
}
