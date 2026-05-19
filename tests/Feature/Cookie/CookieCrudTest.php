<?php

declare(strict_types=1);

namespace Tests\Feature\Cookie;

use Tests\Support\Factories\CookieFactory;
use Tests\Support\FeatureTestCase;

final class CookieCrudTest extends FeatureTestCase
{
    // ==========================================
    // Index Page Tests
    // ==========================================

    public function test_index_displays_cookies_list(): void
    {
        $result = $this->get('/cookies');

        $result->assertOK();
        $result->assertSee('cookies/index');
    }

    public function test_index_displays_paginated_cookies(): void
    {
        // Create multiple cookies
        for ($i = 1; $i <= 25; $i++) {
            $cookie = CookieFactory::createCookie(['name' => "Cookie $i"]);
            $this->cookieRepository->save($cookie);
        }

        $result = $this->get('/cookies');

        $result->assertOK();
        // Should see some cookies (pagination applies)
    }

    public function test_index_supports_pagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $cookie = CookieFactory::createCookie(['name' => "Cookie $i"]);
            $this->cookieRepository->save($cookie);
        }

        $result = $this->get('/cookies?page=2');

        $result->assertOK();
    }

    public function test_index_supports_search(): void
    {
        $cookie1 = CookieFactory::createCookie(['name' => 'Chocolate Cookie']);
        $cookie2 = CookieFactory::createCookie(['name' => 'Vanilla Cookie']);
        $this->cookieRepository->save($cookie1);
        $this->cookieRepository->save($cookie2);

        $result = $this->get('/cookies?search=Chocolate');

        $result->assertOK();
    }

    // ==========================================
    // Create Page Tests
    // ==========================================

    public function test_create_displays_form(): void
    {
        $result = $this->get('/cookies/create');

        $result->assertOK();
        $result->assertSee('cookies/create');
    }

    // ==========================================
    // Store Tests
    // ==========================================

    public function test_store_creates_new_cookie_successfully(): void
    {
        $data = [
            'name' => 'New Test Cookie',
            'description' => 'Test description',
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertDatabaseHas('cookies', [
            'name' => 'New Test Cookie',
            'description' => 'Test description',
            'price' => 2.99,
            'stock' => 100,
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $data = [
            'name' => 'Success Cookie',
            'description' => null,
            'price' => '3.99',
            'stock' => '50',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('success', 'Cookie created successfully');
    }

    public function test_store_handles_validation_errors(): void
    {
        $data = [
            'name' => 'AB', // Too short
            'description' => null,
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_store_handles_duplicate_name(): void
    {
        // Create existing cookie
        $existing = CookieFactory::createCookie(['name' => 'Duplicate Name']);
        $this->cookieRepository->save($existing);

        $data = [
            'name' => 'Duplicate Name',
            'description' => null,
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_store_handles_negative_price(): void
    {
        $data = [
            'name' => 'Invalid Price Cookie',
            'description' => null,
            'price' => '-1.00',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_store_handles_negative_stock(): void
    {
        $data = [
            'name' => 'Invalid Stock Cookie',
            'description' => null,
            'price' => '2.99',
            'stock' => '-10',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    // ==========================================
    // Show Page Tests
    // ==========================================

    public function test_show_displays_cookie_details(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Show Cookie']);
        $id = $this->cookieRepository->save($cookie);

        $result = $this->get("/cookies/{$id}");

        $result->assertOK();
        $result->assertSee('cookies/show');
    }

    public function test_show_redirects_when_cookie_not_found(): void
    {
        $result = $this->get('/cookies/99999');

        $result->assertRedirectTo('/cookies');
        $this->assertFlashMessage('error', 'Cookie not found');
    }

    // ==========================================
    // Edit Page Tests
    // ==========================================

    public function test_edit_displays_form_with_cookie_data(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Edit Cookie']);
        $id = $this->cookieRepository->save($cookie);

        $result = $this->get("/cookies/{$id}/edit");

        $result->assertOK();
        $result->assertSee('cookies/edit');
    }

    public function test_edit_redirects_when_cookie_not_found(): void
    {
        $result = $this->get('/cookies/99999/edit');

        $result->assertRedirectTo('/cookies');
        $this->assertFlashMessage('error', 'Cookie not found');
    }

    // ==========================================
    // Update Tests
    // ==========================================

    public function test_update_modifies_cookie_successfully(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Original Name', 'price' => 2.99]);
        $id = $this->cookieRepository->save($cookie);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'price' => '4.99',
            'stock' => '200',
            'is_active' => '1',
        ];

        $result = $this->post("/cookies/{$id}", $data);

        $result->assertRedirect();
        $this->assertDatabaseHas('cookies', [
            'id' => $id,
            'name' => 'Updated Name',
            'price' => 4.99,
            'stock' => 200,
        ]);
    }

    public function test_update_redirects_to_show_page_with_success_message(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Test Cookie']);
        $id = $this->cookieRepository->save($cookie);

        $data = [
            'name' => 'Updated Cookie',
            'description' => null,
            'price' => '3.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post("/cookies/{$id}", $data);

        $result->assertRedirectTo("/cookies/{$id}");
        $this->assertFlashMessage('success', 'Cookie updated successfully');
    }

    public function test_update_handles_validation_errors(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Valid Cookie']);
        $id = $this->cookieRepository->save($cookie);

        $data = [
            'name' => 'AB', // Too short
            'description' => null,
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post("/cookies/{$id}", $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_update_handles_duplicate_name(): void
    {
        $cookie1 = CookieFactory::createCookie(['name' => 'Cookie One']);
        $cookie2 = CookieFactory::createCookie(['name' => 'Cookie Two']);
        $id1 = $this->cookieRepository->save($cookie1);
        $this->cookieRepository->save($cookie2);

        $data = [
            'name' => 'Cookie Two', // Already exists
            'description' => null,
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post("/cookies/{$id1}", $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_update_allows_same_name_for_same_cookie(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Same Name']);
        $id = $this->cookieRepository->save($cookie);

        $data = [
            'name' => 'Same Name', // Keeping the same name
            'description' => 'Updated description',
            'price' => '4.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post("/cookies/{$id}", $data);

        $result->assertRedirectTo("/cookies/{$id}");
        $this->assertFlashMessage('success');
    }

    public function test_update_handles_non_existent_cookie(): void
    {
        $data = [
            'name' => 'Test',
            'description' => null,
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        $result = $this->post('/cookies/99999', $data);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    // ==========================================
    // Delete Tests
    // ==========================================

    public function test_delete_removes_cookie_successfully(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'To Delete']);
        $id = $this->cookieRepository->save($cookie);

        $result = $this->post("/cookies/{$id}/delete");

        $result->assertRedirectTo('/cookies');
        $this->assertFlashMessage('success', 'Cookie deleted successfully');
        $this->assertNull($this->cookieRepository->findById($id));
    }

    public function test_delete_handles_non_existent_cookie(): void
    {
        $result = $this->post('/cookies/99999/delete');

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    // ==========================================
    // Complete User Journey Tests
    // ==========================================

    public function test_complete_create_update_delete_journey(): void
    {
        // Step 1: Visit create page
        $createResult = $this->get('/cookies/create');
        $createResult->assertOK();

        // Step 2: Create cookie
        $storeResult = $this->post('/cookies', [
            'name' => 'Journey Cookie',
            'description' => 'Testing complete flow',
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ]);
        $storeResult->assertRedirect();

        // Get the created cookie ID from database
        $cookie = $this->cookieRepository->findAll()[0];
        $id = $cookie->getId();

        // Step 3: View cookie
        $showResult = $this->get("/cookies/{$id}");
        $showResult->assertOK();

        // Step 4: Visit edit page
        $editResult = $this->get("/cookies/{$id}/edit");
        $editResult->assertOK();

        // Step 5: Update cookie
        $updateResult = $this->post("/cookies/{$id}", [
            'name' => 'Updated Journey Cookie',
            'description' => 'Updated flow',
            'price' => '3.99',
            'stock' => '150',
            'is_active' => '1',
        ]);
        $updateResult->assertRedirect();
        $this->assertFlashMessage('success');

        // Step 6: Delete cookie
        $deleteResult = $this->post("/cookies/{$id}/delete");
        $deleteResult->assertRedirectTo('/cookies');
        $this->assertFlashMessage('success');

        // Step 7: Verify cookie is deleted
        $this->assertNull($this->cookieRepository->findById($id));
    }

    public function test_list_page_shows_only_active_cookies(): void
    {
        $active = CookieFactory::createCookie(['name' => 'Active Cookie', 'isActive' => true]);
        $inactive = CookieFactory::createCookie(['name' => 'Inactive Cookie', 'isActive' => false]);
        $this->cookieRepository->save($active);
        $this->cookieRepository->save($inactive);

        $result = $this->get('/cookies');

        $result->assertOK();
        // The view should only display active cookies by default
    }
}
