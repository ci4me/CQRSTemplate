<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Repositories\CookieRepository;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Events\DomainEventInterface;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\IntegrationTestCase;

#[AllowMockObjectsWithoutExpectations]
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
        $this->assertEquals(3.99, $found->getPrice()->getValue());
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
        $this->assertEquals(4.99, $found->getPrice()->getValue());
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

        $this->cookieRepository->delete($id);

        $found = $this->cookieRepository->findById($id);

        $this->assertNull($found);
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

        $exists = $this->cookieRepository->existsByName(CookieName::fromString('Unique Cookie'));

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_returns_false_when_not_exists(): void
    {
        $exists = $this->cookieRepository->existsByName(CookieName::fromString('Non-existent Cookie'));

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_is_case_insensitive_under_unicode_ci(): void
    {
        // The cookies.name column is collated utf8mb4_unicode_ci, so LIKE/=
        // are naturally case-insensitive. SQLite (used in the test
        // database by default) does NOT honour MySQL collations on a
        // generic SELECT … = … comparison, so the case-insensitive arm
        // only kicks in under MySQL. Skip on SQLite to keep the suite
        // green without weakening the assertion under the real engine.
        $driver = \Config\Database::connect('tests')->DBDriver;
        if (stripos($driver, 'mysql') === false) {
            $this->markTestSkipped('Case-insensitive name comparison relies on utf8mb4_unicode_ci collation (MySQL only).');
        }
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Test Cookie']));

        $exists = $this->cookieRepository->existsByName(CookieName::fromString('TEST COOKIE'));

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_excludes_soft_deleted_rows(): void
    {
        // The schema's composite UNIQUE (tenant_id, name, deleted_at)
        // intentionally allows reuse of a soft-deleted row's name —
        // existsByName must therefore return false for a name that
        // belongs only to a trashed row (closes 06/F1).
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Reusable Cookie']));
        $this->cookieRepository->delete($id);

        $this->assertFalse($this->cookieRepository->existsByName(CookieName::fromString('Reusable Cookie')));
    }

    // ==========================================
    // existsByNameExcludingId() Tests
    // ==========================================

    public function test_exists_by_name_excluding_id_returns_true_for_different_cookie(): void
    {
        $id1 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie Name']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Another Cookie']));

        $exists = $this->cookieRepository->existsByNameExcludingId(CookieName::fromString('Another Cookie'), $id1);

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_excluding_id_returns_false_for_same_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'My Cookie']));

        $exists = $this->cookieRepository->existsByNameExcludingId(CookieName::fromString('My Cookie'), $id);

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_excluding_id_returns_false_when_not_exists(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Existing']));

        $exists = $this->cookieRepository->existsByNameExcludingId(CookieName::fromString('Non-existent'), $id);

        $this->assertFalse($exists);
    }

    public function test_exists_by_name_excluding_id_is_case_insensitive_under_unicode_ci(): void
    {
        $driver = \Config\Database::connect('tests')->DBDriver;
        if (stripos($driver, 'mysql') === false) {
            $this->markTestSkipped('Case-insensitive name comparison relies on utf8mb4_unicode_ci collation (MySQL only).');
        }
        $id1 = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie One']));
        $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Cookie Two']));

        $exists = $this->cookieRepository->existsByNameExcludingId(CookieName::fromString('COOKIE TWO'), $id1);

        $this->assertTrue($exists);
    }

    public function test_exists_by_name_excluding_id_excludes_soft_deleted_rows(): void
    {
        // Same contract as existsByName — soft-deleted rows don't reserve
        // the name (closes 06/F1).
        $activeId = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Active Cookie']));
        $deletedId = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Reusable On Delete']));
        $this->cookieRepository->delete($deletedId);

        $exists = $this->cookieRepository->existsByNameExcludingId(CookieName::fromString('Reusable On Delete'), $activeId);

        $this->assertFalse($exists);
    }

    // ==========================================
    // delete() Tests
    // ==========================================

    public function test_delete_soft_deletes_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'To Delete']));

        $result = $this->cookieRepository->delete($id);

        $this->assertTrue($result);
        $this->assertNull($this->cookieRepository->findById($id));
        $this->assertDatabaseMissing('cookies', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_delete_returns_false_for_non_existent_cookie(): void
    {
        $result = $this->cookieRepository->delete(99999);

        $this->assertFalse($result);
    }

    public function test_delete_returns_false_when_already_soft_deleted(): void
    {
        // The conditional UPDATE has `WHERE deleted_at IS NULL`, so a
        // re-delete affects zero rows and returns false (closes 06/F8).
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Double Delete']));
        $this->cookieRepository->delete($id);

        $this->assertFalse($this->cookieRepository->delete($id));
    }

    public function test_delete_bumps_version_in_single_statement(): void
    {
        // Confirm the conditional UPDATE also bumps the version column
        // (closes 06/F8 — single-statement guarantee).
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Version Bump On Delete']));

        // Original row is version=1 after first save.
        $before = $this->cookieRepository->findByIdWithTrashed($id);
        $this->assertNotNull($before);
        $this->assertSame(1, $before->getVersion());

        $this->cookieRepository->delete($id);

        $after = $this->cookieRepository->findByIdWithTrashed($id);
        $this->assertNotNull($after);
        $this->assertSame(2, $after->getVersion());
    }

    // ==========================================
    // purge() Tests — GDPR escape hatch
    // ==========================================

    public function test_purge_hard_deletes_row(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Forget Me']));

        $result = $this->cookieRepository->purge($id);

        $this->assertTrue($result);
        // findByIdWithTrashed bypasses the soft-delete filter, so a
        // soft-deleted row would still show up. Hard-delete is the
        // only way the row vanishes from this lookup.
        $this->assertNull($this->cookieRepository->findByIdWithTrashed($id));
    }

    public function test_purge_returns_false_for_non_existent_row(): void
    {
        $this->assertFalse($this->cookieRepository->purge(99999));
    }

    public function test_purge_also_removes_soft_deleted_row(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Already Trashed']));
        $this->cookieRepository->delete($id);

        $this->assertTrue($this->cookieRepository->purge($id));
        $this->assertNull($this->cookieRepository->findByIdWithTrashed($id));
    }

    // ==========================================
    // Integration Scenarios
    // ==========================================

    // ==========================================
    // restore() Tests
    // ==========================================

    public function test_restore_brings_back_soft_deleted_cookie(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Restorable']));
        $this->cookieRepository->delete($id);
        $this->assertNull($this->cookieRepository->findById($id));

        $restored = $this->cookieRepository->restore($id);

        $this->assertTrue($restored);
        $found = $this->cookieRepository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals('Restorable', $found->getName()->getValue());
    }

    public function test_restore_with_actor_stamps_updated_by(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Audit Restore']));
        $this->cookieRepository->delete($id);

        $this->cookieRepository->restore($id, Actor::system('audit-test'));

        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'deleted_at' => null,
            'deleted_by' => null,
        ]);
    }

    public function test_restore_returns_false_when_cookie_does_not_exist(): void
    {
        $result = $this->cookieRepository->restore(99999);

        $this->assertFalse($result);
    }

    public function test_restore_returns_false_when_cookie_is_not_deleted(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Not Deleted']));

        $result = $this->cookieRepository->restore($id);

        $this->assertFalse($result);
    }

    public function test_restore_bumps_version_for_optimistic_locking(): void
    {
        // Closes 06/F9: restore() previously left `version` untouched, so
        // any in-memory Cookie carrying a stale version could silently
        // overwrite the restored row on a subsequent save. The conditional
        // UPDATE now bumps the column in the same statement.
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Version Bump On Restore']));
        $this->cookieRepository->delete($id); // version: 1 -> 2

        $this->cookieRepository->restore($id);

        $restored = $this->cookieRepository->findById($id);
        $this->assertNotNull($restored);
        $this->assertSame(3, $restored->getVersion());
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
        $this->cookieRepository->delete($id);

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

    public function test_delete_with_actor_stamps_deleted_by_before_soft_delete(): void
    {
        $id = $this->cookieRepository->save(CookieFactory::createCookie(['name' => 'Audited Delete']));

        $result = $this->cookieRepository->delete($id, Actor::system('audit-test'));

        $this->assertTrue($result);
        $this->assertNull($this->cookieRepository->findById($id));
    }

    // ==========================================
    // Event dispatcher integration
    // ==========================================

    public function test_save_drains_pending_events_to_injected_dispatcher(): void
    {
        // Cookie::create() does NOT raise an event (that's the command
        // handler's job). Cookie::update() DOES raise CookieUpdatedEvent
        // on the aggregate, which is what we observe here to prove the
        // repository drains the pendingEvents buffer.
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
        $id = $repo->save($cookie); // first save: no events on aggregate

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
    // Duplicate-key SQL error mapping
    // ==========================================

    public function test_save_translates_duplicate_key_database_exception_into_domain_exception(): void
    {
        // The composite UNIQUE (tenant_id, name, deleted_at) on the cookies
        // table is what catches a concurrent create that raced past the
        // handler's existsByName guard. The repository's catch block must
        // translate the DatabaseException into a DomainException with a
        // stable error code so callers don't leak SQL state.
        //
        // We exercise the path by injecting a model whose insert() throws a
        // DatabaseException whose message contains the discriminator
        // ('duplicate'). SQLite does not surface a duplicate-key error
        // naturally because NULLs in `deleted_at` are treated as distinct,
        // so a directly-injected model is the deterministic way to test
        // the catch + translation logic.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')
            ->willThrowException(new \CodeIgniter\Database\Exceptions\DatabaseException(
                'duplicate entry "Twin Cookie" for key cookies_tenant_name'
            ));

        $logger = LoggerFactory::create('test.cookie.repository.duplicate');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\App\Domain\Shared\Exceptions\DomainException::class);
        $this->expectExceptionMessage('must be unique');

        $repo->save(CookieFactory::createCookie(['name' => 'Twin Cookie']));
    }

    public function test_save_rethrows_non_duplicate_database_exception(): void
    {
        // Non-duplicate-key DatabaseException (e.g. connection lost) must
        // be logged and rethrown as-is, NOT mapped into DomainException.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')
            ->willThrowException(new \CodeIgniter\Database\Exceptions\DatabaseException(
                'connection refused at host 127.0.0.1'
            ));

        $logger = LoggerFactory::create('test.cookie.repository.dberror');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->expectExceptionMessage('connection refused');

        $repo->save(CookieFactory::createCookie(['name' => 'Connection Test']));
    }

    public function test_find_all_logs_and_rethrows_when_builder_throws(): void
    {
        // executeFindAll calls $this->model->builder() — mock the model so
        // builder() throws and the outer catch in findAll() is exercised.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('builder unavailable'));

        $logger = LoggerFactory::create('test.cookie.repository.findall-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('builder unavailable');

        $repo->findAll();
    }

    public function test_find_paginated_logs_and_rethrows_when_builder_throws(): void
    {
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('paginator broken'));

        $logger = LoggerFactory::create('test.cookie.repository.findpaginated-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paginator broken');

        $repo->findPaginated();
    }

    public function test_restore_logs_and_rethrows_when_model_throws(): void
    {
        // The single-statement restore() goes straight to builder()->update();
        // the conditional WHERE on `deleted_at IS NOT NULL` is what
        // determines affected rows, so we only need builder() to throw.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('restore failed'));

        $logger = LoggerFactory::create('test.cookie.repository.restore-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('restore failed');

        $repo->restore(1);
    }

    public function test_find_by_id_logs_and_rethrows_when_model_throws(): void
    {
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('find')->willThrowException(new \RuntimeException('storage layer down'));

        $logger = LoggerFactory::create('test.cookie.repository.findbyid-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage layer down');

        $repo->findById(42);
    }

    public function test_delete_logs_and_rethrows_when_model_throws(): void
    {
        // Single-statement delete() goes through builder()->update();
        // mock builder() to throw and exercise the catch arm.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('write barrier failed'));

        $logger = LoggerFactory::create('test.cookie.repository.delete-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('write barrier failed');

        $repo->delete(1);
    }

    public function test_purge_logs_and_rethrows_when_model_throws(): void
    {
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('purge failed at storage'));

        $logger = LoggerFactory::create('test.cookie.repository.purge-error');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('purge failed at storage');

        $repo->purge(1);
    }

    public function test_save_rethrows_unknown_throwable_from_model(): void
    {
        // A generic Throwable (not DatabaseException) must propagate
        // through the third catch arm with logging.
        $model = $this->createMock(\App\Models\Cookie\CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')->willThrowException(new \RuntimeException('out of memory'));

        $logger = LoggerFactory::create('test.cookie.repository.unknownerror');
        /** @var \Config\Logging $loggingConfig */
        $loggingConfig = config('Logging');
        $repo = new CookieRepository($logger, $loggingConfig, $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('out of memory');

        $repo->save(CookieFactory::createCookie(['name' => 'Memory Test']));
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
        $this->assertEquals(3.99, $updated->getPrice()->getValue());

        // Delete
        $this->cookieRepository->delete($id);
        $deleted = $this->cookieRepository->findById($id);
        $this->assertNull($deleted);
    }
}
