<?php

declare(strict_types=1);

namespace Tests\Feature\Cookie;

use Tests\Support\Factories\CookieFactory;
use Tests\Support\FeatureTestCase;

/**
 * End-to-end test for the Cookie read-model query path.
 *
 * Exercises the full HTTP stack: route → controller → query bus →
 * GetXxxHandler → CookieQueryRepository → DB → view. The existing
 * CookieCrudTest only asserts assertOK() for the index/show routes,
 * which means a regression in the query path (e.g. read-repo returning
 * empty data, or controller dropping the search term) would not fail.
 * This test pins the actual rendered content so the wire end-to-end
 * stays observable.
 */
final class CookieQueryE2ETest extends FeatureTestCase
{
    public function test_index_route_renders_seeded_cookies_in_view(): void
    {
        $this->seedCookies([
            ['name' => 'Snickerdoodle E2E', 'price' => '3.25', 'stock' => 12],
            ['name' => 'Pecan Sandies E2E', 'price' => '4.50', 'stock' => 0],
            ['name' => 'Macaroon E2E',      'price' => '2.10', 'stock' => 99],
        ]);

        $result = $this->get('/cookies');

        $result->assertOK();
        $result->assertSee('Snickerdoodle E2E');
        $result->assertSee('Pecan Sandies E2E');
        $result->assertSee('Macaroon E2E');
    }

    public function test_index_route_with_search_filters_results(): void
    {
        $this->seedCookies([
            ['name' => 'Apple Pie E2E'],
            ['name' => 'Apricot Bar E2E'],
            ['name' => 'Banana Bread E2E'],
        ]);

        $result = $this->get('/cookies?search=Apri');

        $result->assertOK();
        $result->assertSee('Apricot Bar E2E');
        $result->assertDontSee('Banana Bread E2E');
    }

    public function test_index_route_supports_explicit_page_parameter(): void
    {
        // Seed enough cookies to fill multiple pages.
        $names = [];
        for ($i = 1; $i <= 25; $i++) {
            $names[] = ['name' => sprintf('E2E Pager Cookie %02d', $i)];
        }
        $this->seedCookies($names);

        $page1 = $this->get('/cookies?page=1');
        $page2 = $this->get('/cookies?page=2');

        $page1->assertOK();
        $page2->assertOK();
    }

    public function test_show_route_renders_single_cookie_detail(): void
    {
        $cookie = CookieFactory::createCookie([
            'name' => 'Detail E2E Cookie',
            'description' => 'A specific detail-rendering test',
            'price' => '7.77',
            'stock' => 3,
            'isActive' => true,
        ]);
        $id = $this->cookieRepository->save($cookie);

        $result = $this->get("/cookies/{$id}");

        $result->assertOK();
        $result->assertSee('Detail E2E Cookie');
    }

    public function test_show_route_redirects_to_index_when_cookie_missing(): void
    {
        $result = $this->get('/cookies/99999');

        $result->assertRedirectTo('/cookies');
    }

    public function test_index_route_handles_zero_cookies_gracefully(): void
    {
        $result = $this->get('/cookies');

        $result->assertOK();
    }

    /**
     * @param list<array<string, mixed>> $seeds
     */
    private function seedCookies(array $seeds): void
    {
        foreach ($seeds as $data) {
            $cookie = CookieFactory::createCookie($data);
            $this->cookieRepository->save($cookie);
        }
    }
}
