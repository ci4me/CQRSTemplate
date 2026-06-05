<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Repositories\CookieRepository;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Events\DomainEventInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\IntegrationTestCase;

final class CookieRepositoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Repository is already initialized in parent IntegrationTestCase
    }

    // ==========================================
    // save() - Insert Tests
    // ==========================================

    public function test_save_inserts_new_cookie(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'New Cookie',
            'description' => 'Test description',
            'price' => 2.99,
            'stock' => 100,
            'isActive' => true,
        ]);

        $id = $this->cookieRepository->save($cookie);

        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'name' => 'New Cookie',
            'description' => 'Test description',
            'price' => 2.99,
            'stock' => 100,
            'is_active' => 1,
        ]);
    }

    public function test_save_inserts_cookie_with_null_description(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'Cookie No Desc',
            'description' => null,
            'price' => 1.99,
        ]);

        $id = $this->cookieRepository->save($cookie);

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'name' => 'Cookie No Desc',
            'description' => null,
        ]);
    }

    public function test_save_inserts_inactive_cookie(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'Inactive Cookie',
            'isActive' => false,
        ]);

        $id = $this->cookieRepository->save($cookie);

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'is_active' => 0,
        ]);
    }

    public function test_save_inserts_cookie_with_zero_stock(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'Out of Stock',
            'stock' => 0,
        ]);

        $id = $this->cookieRepository->save($cookie);

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'stock' => 0,
        ]);
    }

    // ==========================================
    // save() - Update Tests
    // ==========================================

    public function test_save_updates_existing_cookie(): void
    {
        // Create initial cookie
        $cookie = CookieFactory::createCookie(['name' => 'Original Name']);
        $id = $this->cookieRepository->save($cookie);

        // Update the cookie
        $found = $this->cookieRepository->findById($id);
        $found->update(
            name: CookieName::fromString('Updated Name'),
            description: 'New description',
            price: CookiePrice::fromString('5.99'),
            stock: 200,
            isActive: false
        );

        $returnedId = $this->cookieRepository->save($found);

        $this->assertEquals($id, $returnedId);
        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'name' => 'Updated Name',
            'description' => 'New description',
            'price' => 5.99,
            'stock' => 200,
            'is_active' => 0,
        ]);
    }

    public function test_save_updates_only_changed_fields(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Test Cookie', 'price' => 2.99]);
        $id = $this->cookieRepository->save($cookie);

        $found = $this->cookieRepository->findById($id);
        $found->update(
            name: CookieName::fromString('Same Name Updated'),
            description: $found->getDescription(),
            price: $found->getPrice(),
            stock: $found->getStock(),
            isActive: $found->getIsActive()
        );

        $this->cookieRepository->save($found);

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'name' => 'Same Name Updated',
        ]);
    }

    // ==========================================
    // findById() Tests
    // ==========================================

    public function test_find_by_id_returns_cookie_when_exists(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Findable Cookie', 'price' => 3.99]);
        $id = $this->cookieRepository->save($cookie);

        $found = $this->cookieRepository->findById($id);

        $this->assertInstanceOf(Cookie::class, $found);
        $this->assertEquals($id, $found->getId());
        $this->assertEquals('Findable Cookie', $found->getName()->getValue());
        $this->assertSame('3.99', $found->getPrice()->toDecimalString());
    }

    public function test_find_by_id_returns_null_when_not_exists(): void
    {
        $found = $this->cookieRepository->findById(99999);

        $this->assertNull($found);
    }

    public function test_find_by_id_loads_all_properties(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'Full Cookie',
            'description' => 'Complete description',
            'price' => 4.99,
            'stock' => 150,
            'isActive' => true,
        ]);
        $id = $this->cookieRepository->save($cookie);

        $found = $this->cookieRepository->findById($id);

        $this->assertEquals('Full Cookie', $found->getName()->getValue());
        $this->assertEquals('Complete description', $found->getDescription());
        $this->assertSame('4.99', $found->getPrice()->toDecimalString());
        $this->assertEquals(150, $found->getStock());
        $this->assertTrue($found->getIsActive());
        $this->assertNotNull($found->getCreatedAt());
        $this->assertNotNull($found->getUpdatedAt());
        $this->assertNull($found->getDeletedAt());
    }

    public function test_find_by_id_does_not_return_soft_deleted(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'To Be Deleted']);
        $id = $this->cookieRepository->save($cookie);

        $this->softDeleteById($id);

        $found = $this->cookieRepository->findById($id);

        $this->assertNull($found);
    }

    /**
     * Round-4 R1 helper: soft-delete through the entity-based contract
     * (markDeleted() raises the event; the repository persists + drains).
     */
    private function softDeleteById(int $id, ?Actor $actor = null): void
    {
        $cookie = $this->cookieRepository->findById($id);
        $this->assertNotNull($cookie, sprintf('Cookie #%d must exist before soft-deleting', $id));
        $cookie->markDeleted($actor);
        $this->cookieRepository->delete($cookie, $actor);
    }

    // ==========================================
    // findAll() Tests
    // ==========================================

    public function test_find_all_returns_only_active_cookies_by_default(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active 1', 'isActive' => true]));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active 2', 'isActive' => true]));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Inactive', 'isActive' => false]));

        $results = $this->cookieRepository->findAll();

        $this->assertCount(2, $results);
        $names = array_map(fn(Cookie $c) => $c->getName()->getValue(), $results);
        $this->assertContains('Active 1', $names);
        $this->assertContains('Active 2', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function test_find_all_includes_inactive_when_requested(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active', 'isActive' => true]));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Inactive', 'isActive' => false]));

        $results = $this->cookieRepository->findAll(includeInactive: true);

        $this->assertCount(2, $results);
        $names = array_map(fn(Cookie $c) => $c->getName()->getValue(), $results);
        $this->assertContains('Active', $names);
        $this->assertContains('Inactive', $names);
    }

    public function test_find_all_returns_empty_array_when_no_cookies(): void
    {
        $results = $this->cookieRepository->findAll();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_find_all_returns_cookie_entities(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie 1']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie 2']));

        $results = $this->cookieRepository->findAll();

        foreach ($results as $cookie) {
            $this->assertInstanceOf(Cookie::class, $cookie);
        }
    }

    // ==========================================
    // findPaginated() Tests
    // ==========================================

    public function test_find_paginated_returns_correct_structure(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->cookieRepository->save(CookieFactory::createCookie(['name' => "Cookie $i"]));
        }

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('perPage', $result);
        $this->assertArrayHasKey('lastPage', $result);
    }

    public function test_find_paginated_returns_correct_pagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->cookieRepository->save(CookieFactory::createCookie(['name' => "Cookie $i"]));
        }

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10);

        $this->assertCount(10, $result['data']);
        $this->assertEquals(25, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(10, $result['perPage']);
        $this->assertEquals(3, $result['lastPage']);
    }

    public function test_find_paginated_returns_second_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->cookieRepository->save(CookieFactory::createCookie(['name' => "Cookie $i"]));
        }

        $result = $this->cookieRepository->findPaginated(page: 2, perPage: 10);

        $this->assertCount(10, $result['data']);
        $this->assertEquals(2, $result['page']);
    }

    public function test_find_paginated_with_search_term(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Chocolate Chip']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Chocolate Fudge']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Vanilla Cookie']));

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10, searchTerm: 'Chocolate');

        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['total']);
    }

    public function test_find_paginated_filters_inactive_by_default(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active', 'isActive' => true]));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Inactive', 'isActive' => false]));

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10);

        $this->assertEquals(1, $result['total']);
    }

    public function test_find_paginated_includes_inactive_when_requested(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active', 'isActive' => true]));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Inactive', 'isActive' => false]));

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10, searchTerm: null, includeInactive: true);

        $this->assertEquals(2, $result['total']);
    }

    public function test_find_paginated_returns_empty_when_no_results(): void
    {
        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10);

        $this->assertEmpty($result['data']);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(1, $result['lastPage']);
    }

    public function test_find_paginated_orders_by_created_at_desc(): void
    {
        $id1 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'First Cookie']));
        sleep(1); // Ensure different timestamps
        $id2 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Second Cookie']));

        $result = $this->cookieRepository->findPaginated(page: 1, perPage: 10);

        // Most recent should be first
        $this->assertEquals($id2, $result['data'][0]->getId());
        $this->assertEquals($id1, $result['data'][1]->getId());
    }

    // ==========================================
    // existsByName() Tests
    // ==========================================

    public function test_exists_by_name_returns_true_when_exists(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Unique Cookie']));

        $exists = $this->cookieRepository->existsByName('Unique Cookie');

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_returns_false_when_not_exists(): void
    {
        $exists = $this->cookieRepository->existsByName('Non-existent Cookie');

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_is_case_insensitive(): void
    {
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Test Cookie']));

        $exists = $this->cookieRepository->existsByName('TEST COOKIE');

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_includes_soft_deleted_cookies(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Reserved Cookie']));
        $this->softDeleteById($id);

        $this->assertTrue($this->cookieRepository->existsByName('Reserved Cookie'));
    }

    // ==========================================
    // existsByNameExcludingId() Tests
    // ==========================================

    public function test_exists_by_name_excluding_id_returns_true_for_different_cookie(): void
    {
        $id1 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie Name']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Another Cookie']));

        $exists = $this->cookieRepository->existsByNameExcludingId('Another Cookie', $id1);

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_excluding_id_returns_false_for_same_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'My Cookie']));

        $exists = $this->cookieRepository->existsByNameExcludingId('My Cookie', $id);

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_excluding_id_returns_false_when_not_exists(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Existing']));

        $exists = $this->cookieRepository->existsByNameExcludingId('Non-existent', $id);

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_excluding_id_is_case_insensitive(): void
    {
        $id1 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie One']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie Two']));

        $exists = $this->cookieRepository->existsByNameExcludingId('COOKIE TWO', $id1);

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_excluding_id_includes_soft_deleted_cookies(): void
    {
        $activeId = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active Cookie']));
        $deletedId = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Deleted But Reserved']));
        $this->softDeleteById($deletedId);

        $exists = $this->cookieRepository->existsByNameExcludingId('Deleted But Reserved', $activeId);

        $this->assertTrue($exists);
    }

    // ==========================================
    // delete() Tests
    // ==========================================

    public function test_delete_soft_deletes_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'To Delete']));
        $cookie = $this->cookieRepository->findById($id);
        $this->assertNotNull($cookie);

        $cookie->markDeleted();
        $this->cookieRepository->delete($cookie);

        $this->assertNull($this->cookieRepository->findById($id));
        $this->assertDatabaseMissing('cookies', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_delete_with_stale_version_throws_concurrent_modification(): void
    {
        // Round-4 R1: the delete UPDATE is guarded by WHERE version = ?.
        // An out-of-band writer bumping the version must surface as a
        // domain-level concurrent-modification, not a silent zero-row no-op.
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Race Delete']));
        $cookie = $this->cookieRepository->findById($id);
        $this->assertNotNull($cookie);

        \Config\Database::connect()->table('cookies')
            ->where('id', $id)
            ->update(['version' => $cookie->getVersion() + 1]);

        $cookie->markDeleted();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modified by someone else');

        $this->cookieRepository->delete($cookie);
    }

    // ==========================================
    // Integration Scenarios
    // ==========================================

    // ==========================================
    // restore() Tests
    // ==========================================

    public function test_restore_brings_back_soft_deleted_cookie_and_bumps_version(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Restorable']));
        $this->softDeleteById($id);
        $this->assertNull($this->cookieRepository->findById($id));

        $trashed = $this->cookieRepository->findByIdWithTrashed($id);
        $this->assertNotNull($trashed);
        $trashed->restore();
        $this->cookieRepository->restore($trashed);

        $found = $this->cookieRepository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals('Restorable', $found->getName()->getValue());
        // insert = v1, soft delete = v2, restore = v3 (round-4 R1: restore
        // participates in optimistic locking instead of bypassing it).
        $this->assertSame(3, $found->getVersion());
    }

    public function test_restore_with_actor_stamps_updated_by(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Audit Restore']));
        $this->softDeleteById($id);

        $trashed = $this->cookieRepository->findByIdWithTrashed($id);
        $this->assertNotNull($trashed);
        $trashed->restore(Actor::system('audit-test'));
        $this->cookieRepository->restore($trashed, Actor::system('audit-test'));

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'deleted_at' => null,
            'deleted_by' => null,
        ]);
    }

    public function test_restore_with_stale_version_throws_concurrent_modification(): void
    {
        // Round-4 R1: a parallel restore (or any write) between load and
        // UPDATE must surface as concurrent-modification — the old
        // implementation reported success even when zero rows changed.
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Race Restore']));
        $this->softDeleteById($id);

        $stale = $this->cookieRepository->findByIdWithTrashed($id);
        $this->assertNotNull($stale);

        // Out-of-band restore: the row is live again with a newer version.
        \Config\Database::connect()->table('cookies')
            ->where('id', $id)
            ->update(['deleted_at' => null, 'version' => $stale->getVersion() + 1]);

        $stale->restore();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modified by someone else');

        $this->cookieRepository->restore($stale);
    }

    public function test_restoring_a_live_cookie_is_a_business_rule_violation(): void
    {
        // Round-4 R1: the "must currently be deleted" invariant lives on the
        // AGGREGATE now (COOKIE_STATE_NOT_DELETED), not in the handler.
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Not Deleted']));
        $cookie = $this->cookieRepository->findById($id);
        $this->assertNotNull($cookie);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not deleted');

        $cookie->restore();
    }

    // ==========================================
    // findByIdWithTrashed() Tests
    // ==========================================

    public function test_find_by_id_with_trashed_returns_active_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Visible']));

        $found = $this->cookieRepository->findByIdWithTrashed($id);

        $this->assertNotNull($found);
        $this->assertEquals('Visible', $found->getName()->getValue());
    }

    public function test_find_by_id_with_trashed_returns_soft_deleted_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Trashed']));
        $this->softDeleteById($id);

        $found = $this->cookieRepository->findByIdWithTrashed($id);

        $this->assertNotNull($found);
        $this->assertTrue($found->isDeleted());
    }

    public function test_find_by_id_with_trashed_returns_null_for_missing(): void
    {
        $this->assertNull($this->cookieRepository->findByIdWithTrashed(99999));
    }

    // ==========================================
    // Audit + actor stamping
    // ==========================================

    public function test_save_with_actor_stamps_created_by_and_updated_by(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Audited Cookie']);

        $id = $this->cookieRepository->save($cookie, Actor::system('audit-test'));

        $this->assertGreaterThan(0, $id);
    }

    public function test_delete_with_actor_stamps_deleted_by(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Audited Delete']));
        $actor = Actor::system('audit-test');

        $this->softDeleteById($id, $actor);

        $this->assertNull($this->cookieRepository->findById($id));
        // Single-statement delete (round-4 R1) folds the audit stamp in.
        $this->assertDatabaseHas('cookies', ['id' => $id, 'deleted_by' => $actor->id]);
    }

    // ==========================================
    // Event dispatcher integration
    // ==========================================

    public function test_save_drains_pending_events_to_injected_dispatcher(): void
    {
        // Round-4 R1: the repository records CookieCreatedEvent on the first
        // save (after id assignment) and drains the aggregate's buffer on
        // every save. We subscribe to CookieUpdatedEvent and assert the
        // update() -> save() path delivers it through the repository drain.
        $dispatched = [];
        $logger = LoggerFactory::create('test.cookie.repository.events');
        $dispatcher = new EventDispatcher($logger);
        $dispatcher->subscribe(
            \App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent::class,
            static function (DomainEventInterface $event) use (&$dispatched): void {
                $dispatched[] = $event;
            }
        );

        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, null, $dispatcher);

        $cookie = CookieFactory::createCookie(['name' => 'Event Drainer']);
        $id = $repo->save($cookie); // first save: drains CookieCreatedEvent (no subscriber here)

        $found = $repo->findById($id);
        $this->assertNotNull($found);
        $found->update(
            name: CookieName::fromString('Drained After Update'),
            description: $found->getDescription(),
            price: CookiePrice::fromString('5.50'),
            stock: $found->getStock(),
            isActive: $found->getIsActive()
        );
        $repo->save($found);

        $this->assertNotEmpty($dispatched);
        $this->assertInstanceOf(
            \App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent::class,
            $dispatched[0]
        );
    }

    // ==========================================
    // Outbox-first lifecycle delivery (round-4 R1)
    // ==========================================

    public function test_lifecycle_events_reach_outbox_and_are_marked_delivered_on_sync_dispatch(): void
    {
        // The full round-4 R1 contract in one flow: create, delete and
        // restore each raise their event on the AGGREGATE, the repository
        // writes it to the outbox in the same transaction, dispatches
        // synchronously, and marks the row delivered so the relay never
        // double-delivers.
        $logger = LoggerFactory::create('test.cookie.repository.outbox');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $dispatcher = new EventDispatcher($logger);
        $writer = new \App\Infrastructure\Outbox\EventOutboxWriter();
        $repo = new CookieRepository($logger, $loggingConfig, null, $dispatcher, $writer);

        $cookie = CookieFactory::createCookie(['name' => 'Outbox Lifecycle']);
        $id = $repo->save($cookie);

        $this->assertDatabaseHas('event_outbox', [
            'aggregate_id' => (string) $id,
            'event_class' => \App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent::class,
            'status' => 'delivered',
        ]);

        $cookie->markDeleted();
        $repo->delete($cookie);

        $this->assertDatabaseHas('event_outbox', [
            'aggregate_id' => (string) $id,
            'event_class' => \App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent::class,
            'status' => 'delivered',
        ]);

        $trashed = $repo->findByIdWithTrashed($id);
        $this->assertNotNull($trashed);
        $trashed->restore();
        $repo->restore($trashed);

        $this->assertDatabaseHas('event_outbox', [
            'aggregate_id' => (string) $id,
            'event_class' => \App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent::class,
            'status' => 'delivered',
        ]);

        // No pending leftovers for this aggregate -> the relay has nothing
        // to re-deliver (the double-delivery defect is closed).
        $this->assertDatabaseMissing('event_outbox', [
            'aggregate_id' => (string) $id,
            'status' => 'pending',
        ]);
    }

    public function test_outbox_rows_stay_pending_without_dispatcher_for_relay_delivery(): void
    {
        // Repository wired with outbox but NO dispatcher (e.g. a CLI
        // context): rows must stay pending so the relay delivers them.
        $logger = LoggerFactory::create('test.cookie.repository.outbox-pending');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $writer = new \App\Infrastructure\Outbox\EventOutboxWriter();
        $repo = new CookieRepository($logger, $loggingConfig, null, null, $writer);

        $cookie = CookieFactory::createCookie(['name' => 'Relay Bound']);
        $id = $repo->save($cookie);

        $this->assertDatabaseHas('event_outbox', [
            'aggregate_id' => (string) $id,
            'event_class' => \App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent::class,
            'status' => 'pending',
        ]);
    }

    // ==========================================
    // Integration Scenarios
    // ==========================================

    public function test_complete_crud_cycle(): void
    {
        // Create
        $cookie = CookieFactory::createCookie(['name' => 'CRUD Cookie', 'price' => 2.99]);
        $id = $this->cookieRepository->save($cookie);
        $this->assertGreaterThan(0, $id);

        // Read
        $found = $this->cookieRepository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals('CRUD Cookie', $found->getName()->getValue());

        // Update
        $found->update(
            name: CookieName::fromString('Updated CRUD Cookie'),
            description: $found->getDescription(),
            price: CookiePrice::fromString('3.99'),
            stock: $found->getStock(),
            isActive: $found->getIsActive()
        );
        $this->cookieRepository->save($found);

        // Verify update
        $updated = $this->cookieRepository->findById($id);
        $this->assertEquals('Updated CRUD Cookie', $updated->getName()->getValue());
        $this->assertSame('3.99', $updated->getPrice()->toDecimalString());

        // Delete
        $this->softDeleteById($id);
        $deleted = $this->cookieRepository->findById($id);
        $this->assertNull($deleted);
    }
}
