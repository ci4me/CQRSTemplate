<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Projections\CookieReadModelProjection;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Infrastructure\Persistence\Repositories\CookieReadModelRepository;
use Tests\Support\IntegrationTestCase;

/**
 * Pins the read-side contract: queries return CookieDTO straight from the
 * `cookie_read_model` projection table (not the write-side source). The
 * test boots the projection on a fresh cookie, then exercises every
 * read-port method against the resulting row.
 */
final class CookieReadModelRepositoryTest extends IntegrationTestCase
{
    private CookieReadModelRepository $readRepo;
    private CookieReadModelProjection $projection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readRepo = new CookieReadModelRepository();
        $this->projection = new CookieReadModelProjection($this->cookieRepository);
    }

    public function test_find_by_id_returns_dto_from_projection_row(): void
    {
        $cookie = $this->saveCookie('Chip', '2.99', 5, true);
        $this->projection->apply(new CookieCreatedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'Chip',
            cookiePrice: '2.99',
            initialStock: 5
        ));

        $dto = $this->readRepo->findById((int) $cookie->getId());

        $this->assertNotNull($dto);
        $this->assertSame('Chip', $dto->name);
        // The projection precomputes the formatted price, so the DTO
        // should carry it without re-formatting in PHP.
        $this->assertSame('$2.99', $dto->formattedPrice);
        $this->assertSame(5, $dto->stock);
        $this->assertTrue($dto->isActive);
    }

    public function test_find_by_id_skips_soft_deleted_rows(): void
    {
        $cookie = $this->saveCookie('Gone', '1.00', 1, true);
        $this->projection->apply(new CookieCreatedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'Gone',
            cookiePrice: '1.00',
            initialStock: 1
        ));
        // Soft-delete via the projection's deleted event handler.
        $this->projection->apply(new CookieDeletedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'Gone'
        ));

        $this->assertNull($this->readRepo->findById((int) $cookie->getId()));
    }

    public function test_find_all_excludes_inactive_by_default(): void
    {
        $active = $this->saveCookie('Active', '1.00', 1, true);
        $inactive = $this->saveCookie('Inactive', '1.00', 1, false);
        $this->projection->apply(new CookieCreatedEvent((int) $active->getId(), 'Active', '1.00', 1));
        $this->projection->apply(new CookieCreatedEvent((int) $inactive->getId(), 'Inactive', '1.00', 1));

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
            $cookie = $this->saveCookie($name, '1.00', 1, true);
            $this->projection->apply(new CookieCreatedEvent(
                cookieId: (int) $cookie->getId(),
                cookieName: $name,
                cookiePrice: '1.00',
                initialStock: 1
            ));
        }

        $page1 = $this->readRepo->findPaginated(page: 1, perPage: 2);
        $this->assertCount(2, $page1['data']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['lastPage']);

        // Case-insensitive search via the precomputed name_search column.
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
