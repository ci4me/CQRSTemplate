<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Infrastructure\Persistence\Repositories\CookieQueryRepository;
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
