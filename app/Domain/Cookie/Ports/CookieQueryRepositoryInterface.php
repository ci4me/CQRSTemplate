<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Ports;

use App\Domain\Cookie\DTOs\CookieDTO;

/**
 * Read-side port for Cookie queries.
 *
 * Query handlers depend on this interface so the read path stays narrower
 * than the write side ({@see CookieRepositoryInterface}):
 *
 *  - returns DTOs ({@see CookieDTO}), never domain entities — the read
 *    path cannot accidentally mutate the aggregate, and queries don't
 *    pay the cost of reconstituting the value objects.
 *  - exposes only the operations a query needs (by id, list, paginated
 *    search). No save / delete / restore.
 *
 * Phase 2 of the stabilization refactor collapsed Cookie's read model
 * into the canonical `cookies` table, so implementations now query the
 * same physical table as the write side. The CQRS code-level separation
 * is still in place: the read repository is a distinct class that returns
 * DTOs and applies its own filters.
 */
interface CookieQueryRepositoryInterface
{
    /**
     * findById.
     *
     * @param int $cookieId
     * @return CookieDTO|null
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
