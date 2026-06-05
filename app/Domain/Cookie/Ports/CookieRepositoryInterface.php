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
     * Case-insensitive existence check by name across LIVE rows only.
     *
     * Round-4 R2 (E11): soft-deleted rows do NOT reserve their name —
     * matching the composite UNIQUE(tenant_id, name, deleted_at) index,
     * which only prevents two simultaneous live rows from sharing a name.
     * Historical rows keep their surrogate id for ERP/audit references.
     */
    public function existsByName(string $name): bool;

    /**
     * Case-insensitive existence check by name, ignoring one specific id.
     *
     * Same semantics as {@see self::existsByName()} (case-insensitive,
     * live rows only), but excludes a single row from the comparison.
     * Used by the update handler to allow "rename a cookie to its own name"
     * (no change) without tripping the uniqueness check.
     */
    public function existsByNameExcludingId(string $name, int $excludeId): bool;

    /**
     * Soft-delete the aggregate (round-4 R1: entity-based contract).
     *
     * The caller MUST have invoked {@see Cookie::markDeleted()} first so the
     * aggregate carries the CookieDeletedEvent; this method persists the
     * `deleted_at`/`deleted_by` flip with an optimistic-locking guard
     * (`WHERE id = ? AND version = ? AND deleted_at IS NULL`), bumps the
     * version, and drains the aggregate's events outbox-first in the same
     * transaction. The actor MUST be non-null in HTTP/user-driven flows;
     * null is reserved for system contexts (background jobs, migrations).
     *
     * @throws \App\Domain\Shared\Exceptions\DomainException Concurrent-modification when zero rows match.
     */
    public function delete(Cookie $cookie, ?Actor $actor = null): void;

    /**
     * Restore a previously soft-deleted aggregate (round-4 R1: entity-based).
     *
     * The caller MUST have invoked {@see Cookie::restore()} first so the
     * aggregate carries the CookieRestoredEvent and a cleared `deletedAt`.
     * Persists the un-delete with an optimistic-locking guard
     * (`WHERE id = ? AND version = ? AND deleted_at IS NOT NULL`), bumps the
     * version, and drains the aggregate's events outbox-first in the same
     * transaction.
     *
     * @throws \App\Domain\Shared\Exceptions\DomainException Concurrent-modification when zero rows match.
     */
    public function restore(Cookie $cookie, ?Actor $actor = null): void;

    /**
     * Find a cookie by id INCLUDING soft-deleted rows.
     *
     * Used by the restore command to verify the target exists in the trash.
     */
    public function findByIdWithTrashed(int $id): ?Cookie;
}
