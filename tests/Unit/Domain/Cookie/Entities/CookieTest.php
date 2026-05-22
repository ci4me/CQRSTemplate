<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Entities;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for Cookie Entity.
 *
 * Tests business logic, state management, and invariants.
 */
final class CookieTest extends UnitTestCase
{
    // ==================== CREATION TESTS ====================

    public function test_can_create_with_valid_data(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Chocolate Chip'),
            description: 'Delicious cookie',
            price: CookiePrice::fromString('2.99'),
            stock: 100,
            isActive: true
        );

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertEquals('Chocolate Chip', $cookie->getName()->getValue());
        $this->assertEquals('Delicious cookie', $cookie->getDescription());
        $this->assertEquals(2.99, $cookie->getPrice()->getValue());
        $this->assertEquals(100, $cookie->getStock());
        $this->assertTrue($cookie->getIsActive());
        $this->assertNull($cookie->getId());
    }

    public function test_can_create_with_null_description(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Simple Cookie'),
            description: null,
            price: CookiePrice::fromString('1.99'),
            stock: 50,
            isActive: true
        );

        $this->assertNull($cookie->getDescription());
    }

    public function test_create_sets_is_active_to_true_by_default(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $this->assertTrue($cookie->getIsActive());
    }

    public function test_throws_exception_for_negative_stock(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be at least 0');

        Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: -1,
            isActive: true
        );
    }

    // ==================== RECONSTITUTE TESTS ====================

    public function test_can_reconstitute_from_database(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Saved Cookie'),
            description: 'From DB',
            price: CookiePrice::fromString('3.99'),
            stock: 75,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: null,
        version: 1
        );

        $this->assertEquals(1, $cookie->getId());
        $this->assertEquals('Saved Cookie', $cookie->getName()->getValue());
        $this->assertEquals('2025-10-21 10:00:00', $cookie->getCreatedAt());
        $this->assertEquals('2025-10-21 10:00:00', $cookie->getUpdatedAt());
        $this->assertNull($cookie->getDeletedAt());
    }

    public function test_reconstitute_with_deleted_at(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 11:00:00',
            deletedAt: '2025-10-21 12:00:00',
        version: 1
        );

        $this->assertEquals('2025-10-21 12:00:00', $cookie->getDeletedAt());
        $this->assertTrue($cookie->isDeleted());
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_all_fields(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Original'),
            description: 'Original desc',
            price: CookiePrice::fromString('2.00'),
            stock: 50,
            isActive: true
        );

        $cookie->update(
            name: CookieName::fromString('Updated'),
            description: 'Updated desc',
            price: CookiePrice::fromString('3.00'),
            stock: 100,
            isActive: false
        );

        $this->assertEquals('Updated', $cookie->getName()->getValue());
        $this->assertEquals('Updated desc', $cookie->getDescription());
        $this->assertEquals(3.00, $cookie->getPrice()->getValue());
        $this->assertEquals(100, $cookie->getStock());
        $this->assertFalse($cookie->getIsActive());
    }

    public function test_update_with_negative_stock_throws_exception(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $this->expectException(ValidationException::class);

        $cookie->update(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: -5,
            isActive: true
        );
    }

    // ==================== STOCK MANAGEMENT TESTS ====================

    public function test_can_increase_stock(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 50,
            isActive: true
        );

        $cookie->assignId(1);

        $cookie->increaseStock(25);

        $this->assertEquals(75, $cookie->getStock());
    }

    public function test_increase_stock_throws_exception_for_negative_amount(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 50,
            isActive: true
        );
        $cookie->assignId(1);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be at least 1');

        $cookie->increaseStock(-10);
    }

    public function test_can_decrease_stock(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 50,
            isActive: true
        );

        $cookie->assignId(1);

        $cookie->decreaseStock(20);

        $this->assertEquals(30, $cookie->getStock());
    }

    public function test_decrease_stock_throws_exception_if_insufficient(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );
        $cookie->assignId(1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stock cannot be negative');

        $cookie->decreaseStock(20);
    }

    public function test_can_decrease_stock_to_exactly_zero(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $cookie->assignId(1);

        $cookie->decreaseStock(10);

        $this->assertEquals(0, $cookie->getStock());
    }

    public function test_decrease_stock_raises_event_on_aggregate(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Stock Event A'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );
        $cookie->assignId(1);

        $this->assertFalse($cookie->hasPendingEvents(), 'fresh aggregate has no events');

        $cookie->decreaseStock(3);

        $events = $cookie->pullEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieStockChangedEvent::class, $events[0]);
        $this->assertEquals(10, $events[0]->previousStock);
        $this->assertEquals(7, $events[0]->newStock);
        $this->assertEquals('decreaseStock', $events[0]->reason);

        $this->assertFalse($cookie->hasPendingEvents(), 'pull drains the buffer');
    }

    public function test_increase_stock_raises_event_on_aggregate(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Stock Event B'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 5,
            isActive: true
        );

        $cookie->assignId(1);

        $cookie->increaseStock(8);

        $events = $cookie->pullEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieStockChangedEvent::class, $events[0]);
        $this->assertEquals(5, $events[0]->previousStock);
        $this->assertEquals(13, $events[0]->newStock);
        $this->assertEquals('increaseStock', $events[0]->reason);
    }

    public function test_has_stock_returns_true_when_available(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $this->assertTrue($cookie->isAvailable());
        $this->assertFalse($cookie->isOutOfStock());
    }

    public function test_is_out_of_stock_returns_true_when_zero(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: true
        );

        $this->assertTrue($cookie->isOutOfStock());
        $this->assertFalse($cookie->isAvailable());
    }

    // ==================== ACTIVATION TESTS ====================

    public function test_can_activate(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: false
        );

        $cookie->assignId(1);

        $cookie->activate();

        $this->assertTrue($cookie->getIsActive());
    }

    public function test_can_deactivate(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $cookie->assignId(1);

        $cookie->deactivate();

        $this->assertFalse($cookie->getIsActive());
    }

    // ==================== SOFT DELETE TESTS ====================

    public function test_is_deleted_returns_true_when_deleted(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: '2025-10-21 12:00:00',
        version: 1
        );

        $this->assertTrue($cookie->isDeleted());
    }

    public function test_is_deleted_returns_false_when_not_deleted(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Test'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: null,
        version: 1
        );

        $this->assertFalse($cookie->isDeleted());
    }

    // ==================== INVARIANT TESTS ====================

    public function test_update_refuses_to_mutate_soft_deleted_cookie(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Trashed'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: '2025-10-21 11:00:00',
            version: 1
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot mutate a soft-deleted cookie');

        $cookie->update(
            CookieName::fromString('New Name'),
            'desc',
            CookiePrice::fromString('2.00'),
            5,
            true
        );
    }

    public function test_activate_refuses_soft_deleted_cookie(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Trashed'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: '2025-10-21 11:00:00',
            version: 1
        );

        $this->expectException(DomainException::class);
        $cookie->activate();
    }

    public function test_stock_op_refuses_unpersisted_cookie(): void
    {
        // No assignId — entity stays in pre-save state with id === null.
        $cookie = Cookie::create(
            name: CookieName::fromString('Ghost'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requires a persisted entity');

        $cookie->decreaseStock(1);
    }

    public function test_assign_id_refuses_to_overwrite_existing_id(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Reassign me'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );
        $cookie->assignId(7);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('refusing to reassign');

        $cookie->assignId(99);
    }

    public function test_assign_id_is_idempotent_for_same_id(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Idempotent'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 10,
            isActive: true
        );

        $cookie->assignId(42);
        $cookie->assignId(42);

        $this->assertSame(42, $cookie->getId());
    }
}
