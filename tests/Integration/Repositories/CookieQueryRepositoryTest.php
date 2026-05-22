<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Repositories\CookieQueryRepository;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Infrastructure\Tenancy\TenantContext;
use Tests\Support\IntegrationTestCase;

/**
 * Pins the read-side contract: queries return CookieDTO straight from the
 * canonical `cookies` table.
 *
 * Phase 2 of the stabilization refactor collapsed Cookie's read model into
 * the canonical `cookies` table, so the test no longer drives a projection;
 * it saves cookies through the write-side repository and then exercises the
 * read repo against the resulting rows.
 */
final class CookieQueryRepositoryTest extends IntegrationTestCase
{
    private CookieQueryRepository $readRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readRepo = new CookieQueryRepository();
    }

    public function test_find_by_id_returns_dto_for_saved_cookie(): void
    {
        $cookie = $this->saveCookie('Chip', '2.99', 5, true);

        $dto = $this->readRepo->findById((int) $cookie->getId());

        $this->assertNotNull($dto);
        $this->assertSame('Chip', $dto->name);
        // The read repo formats the price in PHP from the stored decimal.
        $this->assertSame('$2.99', $dto->formattedPrice);
        $this->assertSame(5, $dto->stock);
        $this->assertTrue($dto->isActive);
    }

    public function test_find_by_id_skips_soft_deleted_rows(): void
    {
        $cookie = $this->saveCookie('Gone', '1.00', 1, true);
        // Soft-delete via the write-side repository.
        $this->cookieRepository->delete((int) $cookie->getId());

        $this->assertNull($this->readRepo->findById((int) $cookie->getId()));
    }

    public function test_find_all_excludes_inactive_by_default(): void
    {
        $this->saveCookie('Active', '1.00', 1, true);
        $this->saveCookie('Inactive', '1.00', 1, false);

        $rows = $this->readRepo->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('Active', $rows[0]->name);

        $allRows = $this->readRepo->findAll(includeInactive: true);
        $this->assertCount(2, $allRows);
    }

    public function test_find_paginated_supports_search_term_and_pagination(): void
    {
        $names = ['Apple Pie', 'Apricot Bar', 'Choco Chunk', 'Date Bar', 'Elderberry'];
        foreach ($names as $name) {
            $this->saveCookie($name, '1.00', 1, true);
        }

        $page1 = $this->readRepo->findPaginated(page: 1, perPage: 2);
        $this->assertCount(2, $page1['data']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['lastPage']);

        // `cookies.name` uses utf8mb4_unicode_ci, so LIKE is case-insensitive
        // without needing a separate `name_search` column.
        $aResults = $this->readRepo->findPaginated(searchTerm: 'AP');
        $this->assertSame(2, $aResults['total']);
        $names = array_map(static fn($dto) => $dto->name, $aResults['data']);
        $this->assertContains('Apple Pie', $names);
        $this->assertContains('Apricot Bar', $names);
    }

    public function test_find_by_id_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->readRepo->findById(99999));
    }

    public function test_find_all_returns_empty_array_when_no_rows(): void
    {
        $this->assertSame([], $this->readRepo->findAll());
    }

    public function test_find_paginated_clamps_page_below_one(): void
    {
        $this->saveCookie('Single', '1.00', 1, true);

        $result = $this->readRepo->findPaginated(page: 0, perPage: 10);

        // page=0 is clamped to 1 by the read repo.
        $this->assertSame(1, $result['page']);
        $this->assertCount(1, $result['data']);
    }

    public function test_find_paginated_clamps_per_page_to_one_when_zero(): void
    {
        $this->saveCookie('Solo', '1.00', 1, true);

        $result = $this->readRepo->findPaginated(page: 1, perPage: 0);

        // perPage=0 is clamped to 1.
        $this->assertSame(1, $result['perPage']);
    }

    public function test_find_paginated_clamps_per_page_to_one_hundred(): void
    {
        $result = $this->readRepo->findPaginated(page: 1, perPage: 9999);

        $this->assertSame(100, $result['perPage']);
    }

    public function test_find_paginated_last_page_is_at_least_one_for_empty_result(): void
    {
        $result = $this->readRepo->findPaginated(page: 1, perPage: 10);

        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['lastPage']);
    }

    public function test_format_price_falls_back_to_raw_for_malformed_value(): void
    {
        // Insert a row with an obviously invalid price directly via the
        // db so CookiePrice::fromString() throws inside formatPrice() and
        // the catch returns the raw decimal string.
        \Config\Database::connect('tests')
            ->table('cookies')
            ->insert([
                'name' => 'Broken Price',
                'price' => 'NOT_A_NUMBER',
                'stock' => 1,
                'is_active' => 1,
                'version' => 1,
            ]);

        $rows = $this->readRepo->findAll();
        $broken = array_values(array_filter($rows, static fn($dto) => $dto->name === 'Broken Price'));

        $this->assertCount(1, $broken);
        $this->assertSame('NOT_A_NUMBER', $broken[0]->formattedPrice);
    }

    public function test_apply_tenant_filter_is_active_when_context_injected(): void
    {
        // Insert two rows with explicit tenant_id so the filter can be
        // observed. The write-repo path uses null tenant context by
        // default, so we go direct-to-DB here.
        $db = \Config\Database::connect('tests');
        $db->table('cookies')->insert([
            'tenant_id' => 1,
            'name' => 'Tenant 1 Cookie',
            'price' => '1.00',
            'stock' => 1,
            'is_active' => 1,
            'version' => 1,
        ]);
        $db->table('cookies')->insert([
            'tenant_id' => 999,
            'name' => 'Tenant 999 Cookie',
            'price' => '1.00',
            'stock' => 1,
            'is_active' => 1,
            'version' => 1,
        ]);

        $tenantA = new TenantContext();
        $tenantA->set(1);
        $repoA = new CookieQueryRepository(null, $tenantA);
        $tenantB = new TenantContext();
        $tenantB->set(999);
        $repoB = new CookieQueryRepository(null, $tenantB);

        $aRows = $repoA->findAll();
        $bRows = $repoB->findAll();

        $this->assertCount(1, $aRows);
        $this->assertSame('Tenant 1 Cookie', $aRows[0]->name);
        $this->assertCount(1, $bRows);
        $this->assertSame('Tenant 999 Cookie', $bRows[0]->name);
    }

    public function test_find_paginated_escapes_like_wildcards_in_search_term(): void
    {
        // Two rows differ ONLY in whether the name contains a literal `%`.
        // If the read repo did NOT escape, the search term `%` would match
        // both rows (because `%` is the SQL wildcard for "any sequence").
        // The escape contract documented on the port forces `%` to be
        // treated as a literal — only the row whose name actually contains
        // a `%` character should come back (closes 06/F4).
        $this->saveCookie('Plain Cookie', '1.00', 1, true);
        $this->saveCookie('Cookie %50% Off', '1.00', 1, true);

        $result = $this->readRepo->findPaginated(searchTerm: '%');

        $this->assertSame(1, $result['total']);
        $names = array_map(static fn($dto) => $dto->name, $result['data']);
        $this->assertContains('Cookie %50% Off', $names);
        $this->assertNotContains('Plain Cookie', $names);
    }

    public function test_find_paginated_escapes_underscore_wildcard_in_search_term(): void
    {
        // `_` is the single-character wildcard in SQL LIKE. Without
        // escape, `_` would match every single character (effectively
        // a one-character substring search). With escape it only
        // matches a literal underscore.
        $this->saveCookie('Plain Cookie', '1.00', 1, true);
        $this->saveCookie('Cookie_underscore', '1.00', 1, true);

        $result = $this->readRepo->findPaginated(searchTerm: '_');

        $this->assertSame(1, $result['total']);
        $names = array_map(static fn($dto) => $dto->name, $result['data']);
        $this->assertContains('Cookie_underscore', $names);
        $this->assertNotContains('Plain Cookie', $names);
    }

    private function saveCookie(string $name, string $price, int $stock, bool $active): Cookie
    {
        $cookie = Cookie::create(
            CookieName::fromString($name),
            $name . ' description',
            CookiePrice::fromString($price),
            $stock,
            $active
        );
        $this->cookieRepository->save($cookie);
        return $cookie;
    }
}
