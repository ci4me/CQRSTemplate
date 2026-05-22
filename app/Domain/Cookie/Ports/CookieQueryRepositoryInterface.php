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
     */
    public function findById(int $cookieId): ?CookieDTO;

    /**
     * @return list<CookieDTO>
     */
    public function findAll(bool $includeInactive = false): array;

    /**
     * Paginate the read side.
     *
     * `$searchTerm` is treated as a literal substring match. Implementations
     * MUST escape SQL `LIKE` wildcards (`%`, `_`, and the escape char `\`)
     * before passing the term to the query builder so user input cannot
     * accidentally expand into a full-table scan or leak rows the user
     * did not target (closes 06/F4).
     *
     * @return array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}
     * @throws \RuntimeException If the underlying SELECT fails — symmetric
     *                           with the write-side repository. Returning
     *                           silent-empty would let a broken read hide
     *                           behind `total = 0`.
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ): array;
}
