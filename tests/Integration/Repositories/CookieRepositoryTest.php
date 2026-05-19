<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
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
        $this->cookieRepository->delete($id);

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
        $this->cookieRepository->delete($deletedId);

        $exists = $this->cookieRepository->existsByNameExcludingId('Deleted But Reserved', $activeId);

        $this->assertTrue($exists);
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
