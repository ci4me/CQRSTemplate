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

    /**
     * Look up a cookie aggregate by id.
     *
     * Returns null when no row matches OR the row is soft-deleted: the
     * implementation hides `deleted_at IS NOT NULL` rows via CI4's
     * `useSoftDeletes = true` model behaviour. Callers that need to see
     * deleted rows (e.g. the restore command) MUST use
     * {@see self::findByIdWithTrashed()} instead.
     */
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

    /**
     * Case-insensitive existence check by name across the live + trashed set.
     *
     * The underlying query does `LOWER(name) = LOWER(?)` AND includes
     * soft-deleted rows — by design. A previously-deleted name still counts as
     * "taken" so ERP/audit references to the historical row are preserved
     * (the same surrogate id can't be reused without a name change first).
     */
    public function existsByName(string $name): bool;

    /**
     * Case-insensitive existence check by name, ignoring one specific id.
     *
     * Same semantics as {@see self::existsByName()} (case-insensitive,
     * includes soft-deleted), but excludes a single row from the comparison.
     * Used by the update handler to allow "rename a cookie to its own name"
     * (no change) without tripping the uniqueness check.
     */
    public function existsByNameExcludingId(string $name, int $excludeId): bool;

    /**
     * Soft-delete the row identified by `$id`.
     *
     * Sets `deleted_at` and stamps `deleted_by` with the supplied actor.
     * Returns false when no row matches (and therefore nothing was deleted).
     * The actor MUST be non-null in HTTP/user-driven flows; null is reserved
     * for system contexts (background jobs, migrations).
     */
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
