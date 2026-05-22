<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Ports;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
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
     * MUTATES the entity:
     *   - On first insert, assigns the generated id via `assignId()`.
     *   - On every successful persist, bumps `version` via `bumpVersion()`.
     *
     * The caller's local `Cookie` reference is updated in place — pass
     * the same reference back to subsequent operations.
     *
     * The optional {@see Actor} stamps the audit columns
     * (`created_by` on first insert, `updated_by` on every subsequent
     * UPDATE). Pass `null` only from contexts where no human acted
     * (migrations, seeds, background reconciliation); HTTP-driven
     * flows MUST pass the resolved request actor.
     */
    public function save(Cookie $cookie, ?Actor $actor = null): int;

    /**
     * findById.
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
     * Check whether a Cookie with the given name exists in the active set.
     *
     * Takes a {@see CookieName} value object — never a raw scalar — so the
     * caller is forced through the input-boundary validation. The check
     * intentionally EXCLUDES soft-deleted rows so the schema's composite
     * UNIQUE (`tenant_id`, `name`, `deleted_at`) reuse-after-soft-delete
     * contract is honoured: a name belonging to a trashed cookie is
     * available for a new active row (closes 06/F1).
     *
     * Comparison is case-insensitive because the `cookies.name` column
     * is collated `utf8mb4_unicode_ci`; no `LOWER()` wrapper is applied
     * (06/F6 — `LOWER()` would void the unique index).
     */
    public function existsByName(CookieName $name): bool;

    /**
     * Same as {@see self::existsByName()} but excludes a specific row id.
     *
     * Used by update handlers to allow a row to keep its own name.
     */
    public function existsByNameExcludingId(CookieName $name, int $excludeId): bool;

    /**
     * Soft-delete a cookie.
     *
     * Single-statement: a conditional UPDATE that sets `deleted_at` only
     * when the row is currently active. Returns true iff exactly one row
     * was affected — false means "not found or already deleted".
     */
    public function delete(int $id, ?Actor $actor = null): bool;

    /**
     * Restore a previously soft-deleted cookie.
     *
     * Conditional UPDATE scoped on `version = ?` AND `deleted_at IS NOT NULL`.
     * The version column is bumped so the in-memory entity (if any) stays
     * in lock-step with the row, closing the optimistic-locking gap
     * (06/F9). Returns true iff exactly one row was affected.
     */
    public function restore(int $id, ?Actor $actor = null): bool;

    /**
     * Find a cookie by id INCLUDING soft-deleted rows.
     *
     * Used by the restore command to verify the target exists in the trash.
     */
    public function findByIdWithTrashed(int $id): ?Cookie;

    /**
     * Hard-delete a cookie row.
     *
     * GDPR / right-to-erasure escape hatch. The normal lifecycle is
     * {@see self::delete()} (soft-delete) + {@see self::restore()};
     * `purge()` permanently removes the row and is intended for
     * legal-compliance flows only. There is no recovery once a purge
     * commits — callers must have authorisation in place.
     *
     * Returns true iff exactly one row was affected.
     */
    public function purge(int $id): bool;
}
