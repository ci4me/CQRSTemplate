<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Ports;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Shared\ValueObjects\Actor;

/**
 * Domain port for Cookie persistence.
 *
 * Command and query handlers depend on this interface so the Cookie domain can
 * be reused as a template without taking a direct dependency on CodeIgniter
 * models or database adapters.
 */
interface CookieRepositoryInterface
{
    /**
     * Persist the aggregate.
     *
     * The optional {@see Actor} stamps the audit columns
     * (`created_by` on first insert, `updated_by` on every subsequent
     * UPDATE). Pass `null` only from contexts where no human acted
     * (migrations, seeds, background reconciliation); HTTP-driven
     * flows MUST pass the resolved request actor.
     */
    public function save(Cookie $cookie, ?Actor $actor = null): int;

    public function findById(int $id): ?Cookie;

    /**
     * @return array<int, Cookie>
     */
    public function findAll(bool $includeInactive = false): array;

    /**
     * @return array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ): array;

    public function existsByName(string $name): bool;

    public function existsByNameExcludingId(string $name, int $excludeId): bool;

    public function delete(int $id, ?Actor $actor = null): bool;

    /**
     * Restore a previously soft-deleted cookie.
     *
     * Looks the row up including soft-deleted rows, clears `deleted_at`, and
     * returns true on success. Returns false if no row matches.
     */
    public function restore(int $id, ?Actor $actor = null): bool;

    /**
     * Find a cookie by id INCLUDING soft-deleted rows.
     *
     * Used by the restore command to verify the target exists in the trash.
     */
    public function findByIdWithTrashed(int $id): ?Cookie;
}
