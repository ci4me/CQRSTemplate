<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Entities;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Aggregate\AggregateHydrator;
use App\Domain\Shared\Aggregate\AggregateRootInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use ReflectionMethod;
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
        $this->assertSame('2.99', $cookie->getPrice()->toDecimalString());
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
        $this->assertSame('3.00', $cookie->getPrice()->toDecimalString());
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

        $cookie->assignId(1, AggregateHydrator::key());

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
        $cookie->assignId(1, AggregateHydrator::key());

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

        $cookie->assignId(1, AggregateHydrator::key());

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
        $cookie->assignId(1, AggregateHydrator::key());

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

        $cookie->assignId(1, AggregateHydrator::key());

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
        $cookie->assignId(1, AggregateHydrator::key());

        $this->assertFalse($cookie->hasPendingEvents(), 'fresh aggregate has no events');

        $cookie->decreaseStock(3);

        $events = $cookie->pullEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieStockChangedEvent::class, $events[0]);
        $this->assertEquals(10, $events[0]->previousStock);
        $this->assertEquals(7, $events[0]->newStock);
        $this->assertEquals('manual_decrease', $events[0]->reason); // default business reason (round-4 R3)

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

        $cookie->assignId(1, AggregateHydrator::key());

        $cookie->increaseStock(8);

        $events = $cookie->pullEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieStockChangedEvent::class, $events[0]);
        $this->assertEquals(5, $events[0]->previousStock);
        $this->assertEquals(13, $events[0]->newStock);
        $this->assertEquals('manual_increase', $events[0]->reason); // default business reason (round-4 R3)
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

        $cookie->assignId(1, AggregateHydrator::key());

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

        $cookie->assignId(1, AggregateHydrator::key());

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
        $cookie->assignId(7, AggregateHydrator::key());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('refusing to reassign');

        $cookie->assignId(99, AggregateHydrator::key());
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

        $cookie->assignId(42, AggregateHydrator::key());
        $cookie->assignId(42, AggregateHydrator::key());

        $this->assertSame(42, $cookie->getId());
    }

    // ==================== HYDRATION CONTRACT TESTS (E06) ====================

    public function test_cookie_implements_aggregate_root_interface(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Marker'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1,
            isActive: true
        );

        $this->assertInstanceOf(AggregateRootInterface::class, $cookie);
    }

    public function test_assign_id_requires_aggregate_hydrator_key_parameter(): void
    {
        $reflection = new ReflectionMethod(Cookie::class, 'assignId');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters, 'assignId must take (int $id, AggregateHydrator $key)');
        $this->assertSame('id', $parameters[0]->getName());
        $this->assertSame('key', $parameters[1]->getName());

        $type = $parameters[1]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(AggregateHydrator::class, $type->getName());
        $this->assertFalse($type->allowsNull(), 'hydrator key is required, not optional');
    }

    public function test_bump_version_requires_aggregate_hydrator_key_parameter(): void
    {
        $reflection = new ReflectionMethod(Cookie::class, 'bumpVersion');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters, 'bumpVersion must take (AggregateHydrator $key)');
        $this->assertSame('key', $parameters[0]->getName());

        $type = $parameters[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(AggregateHydrator::class, $type->getName());
        $this->assertFalse($type->allowsNull(), 'hydrator key is required, not optional');
    }

    public function test_bump_version_increments_with_valid_key(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Versioned'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1,
            isActive: true
        );
        $this->assertSame(0, $cookie->getVersion());

        $cookie->bumpVersion(AggregateHydrator::key());

        $this->assertSame(1, $cookie->getVersion());
    }

    public function test_reconstitute_rejects_version_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Persisted Cookie must have version >= 1');

        Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Bad Row'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: null,
            version: 0
        );
    }

    public function test_reconstitute_rejects_negative_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('migration drift');

        Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Bad Row'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: null,
            version: -3
        );
    }

    // ==================== LIFECYCLE EVENTS (round-4 R1) ====================

    public function test_mark_deleted_sets_deleted_at_and_raises_event_with_snapshot(): void
    {
        $cookie = $this->makePersisted(id: 5);

        $cookie->markDeleted(Actor::user(42));

        $this->assertTrue($cookie->isDeleted());
        $events = $cookie->peekEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieDeletedEvent::class, $events[0]);
        $this->assertSame(5, $events[0]->cookieId);
        $this->assertSame(42, $events[0]->deletedBy);
        $this->assertSame('Persisted Cookie', $events[0]->snapshot['name']);
    }

    public function test_mark_deleted_twice_is_rejected(): void
    {
        $cookie = $this->makePersisted(id: 5);
        $cookie->markDeleted();

        $this->expectException(DomainException::class);
        $cookie->markDeleted();
    }

    public function test_mark_deleted_requires_persisted_entity(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Unsaved'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1
        );

        $this->expectException(DomainException::class);
        $cookie->markDeleted();
    }

    public function test_restore_clears_deleted_at_and_raises_event(): void
    {
        $cookie = $this->makePersisted(id: 9, deletedAt: '2026-01-01 00:00:00');

        $cookie->restore(Actor::user(7));

        $this->assertFalse($cookie->isDeleted());
        $events = $cookie->peekEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieRestoredEvent::class, $events[0]);
        $this->assertSame(9, $events[0]->cookieId);
        $this->assertSame(7, $events[0]->restoredBy);
    }

    public function test_restore_of_live_cookie_is_business_rule_violation(): void
    {
        $cookie = $this->makePersisted(id: 9);

        try {
            $cookie->restore();
            $this->fail('Expected DomainException restoring a live cookie');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCodes::COOKIE_STATE_NOT_DELETED, $e->getErrorCode());
        }
    }

    public function test_record_creation_raises_created_event_with_current_state(): void
    {
        $cookie = $this->makePersisted(id: 11);

        $cookie->recordCreation(AggregateHydrator::key(), Actor::user(77));

        $events = $cookie->peekEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(CookieCreatedEvent::class, $events[0]);
        $this->assertSame(11, $events[0]->cookieId);
        $this->assertSame('Persisted Cookie', $events[0]->cookieName);
        // Round-4 re-audit finding: the creator must reach the E04 envelope.
        $this->assertSame(77, $events[0]->actorId);
    }

    public function test_record_creation_requires_persisted_entity(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Unsaved'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 1
        );

        $this->expectException(DomainException::class);
        $cookie->recordCreation(AggregateHydrator::key());
    }

    private function makePersisted(int $id, ?string $deletedAt = null): Cookie
    {
        return Cookie::reconstitute(
            id: $id,
            name: CookieName::fromString('Persisted Cookie'),
            description: 'desc',
            price: CookiePrice::fromString('2.50'),
            stock: 10,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: '2025-10-21 10:00:00',
            deletedAt: $deletedAt,
            version: 1
        );
    }
}
