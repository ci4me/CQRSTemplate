<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Ports;

use App\Domain\Cookie\DTOs\CookieDTO;

/**
 * Read-side port for Cookie queries (D15).
 *
 * Query handlers depend on this interface so they read from the
 * denormalised `cookie_read_model` table that {@see \App\Domain\Cookie\Projections\CookieReadModelProjection}
 * keeps in sync with the write side. The interface is intentionally
 * narrower than {@see CookieRepositoryInterface}:
 *
 *  - returns DTOs ({@see CookieDTO}), never domain entities — the read
 *    path cannot accidentally mutate the aggregate, and queries don't
 *    pay the cost of reconstituting the value objects.
 *  - exposes only the operations a query needs (by id, list, paginated
 *    search). No save / delete / restore.
 *
 * The cookie_read_model row carries the fields the UI cares about
 * (denormalised price, name_search, computed `available` flag), so the
 * port methods can stay simple.
 */
interface CookieReadModelRepositoryInterface
{
    /**
     * findById.
     *
     * @param int $cookieId
     * @return CookieDTO|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function findById(int $cookieId): ?CookieDTO;

    /**
     * @param bool $includeInactive
     * @return list<CookieDTO>
     */
    public function findAll(bool $includeInactive = false): array;

    /**
     * @param int         $page
     * @param int         $perPage
     * @param string|null $searchTerm
     * @param bool        $includeInactive
     * @return array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ): array;
}
