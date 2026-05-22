<?php

declare(strict_types=1);

namespace App\Models\Cookie;

use CodeIgniter\Model;

/**
 * CodeIgniter 4 Model for the cookies table.
 *
 * This model provides the database interface for cookie persistence.
 * It's part of the Infrastructure layer, NOT the domain layer.
 *
 * Separation of Concerns:
 * - Model: Database operations (CI4 framework specific)
 * - Repository: Domain interface, returns domain entities
 * - Entity: Business logic and rules (framework agnostic)
 *
 * Why separate Model and Repository:
 * - Model is CI4-specific (can't change framework without changing Model)
 * - Repository returns domain entities (technology agnostic)
 * - Domain layer doesn't depend on CI4
 * - Easy to swap persistence implementation
 *
 * Validation policy (06/F16):
 *   `$validationRules` carries DB-shape safety ONLY (required, type,
 *   max length). Business range checks — minimum name length, positive
 *   price, non-negative stock — are value-object invariants and
 *   duplicating them here silently swallows the VO's structured error
 *   codes when CI4 short-circuits the insert before the repository
 *   even runs.
 *
 * Finalization carve-out (06/F11):
 *   Intentionally NOT `final`. The integration tests in
 *   {@see \Tests\Integration\Repositories\CookieRepositoryTest} drive
 *   error-branch coverage by mocking this model
 *   (`$this->createMock(CookieModel::class)`); PHPUnit cannot mock a
 *   final class out of the box. Until those tests are migrated to a
 *   thin port wrapper, the class stays open for substitution. The
 *   Slevomat `RequireAbstractOrFinal` rule already excludes
 *   `app/Models/` (see `phpcs.xml`), so this is enforced project-wide
 *   for CI4 models and recorded here for future readers.
 *
 * @package App\Models\Cookie
 */
class CookieModel extends Model
{
    protected $table = 'cookies';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'name',
        'description',
        'price',
        'stock',
        'is_active',
        'version',
        'tenant_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    // Validation rules — DB-shape safety ONLY (06/F16).
    //
    // Business ranges (e.g. min name length, price > 0, stock >= 0) are
    // value-object invariants — see CookieName, CookiePrice. Duplicating
    // them here would silently swallow the VO's structured error codes
    // because CI4 fails the insert before the repository's catch can
    // translate the DB error.
    protected $validationRules = [
        'name' => 'required|max_length[100]',
        'price' => 'required|decimal',
        'stock' => 'required|integer',
        'is_active' => 'in_list[0,1]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Cookie name is required',
            'max_length' => 'Cookie name cannot exceed 100 characters',
        ],
        'price' => [
            'required' => 'Price is required',
            'decimal' => 'Price must be a valid decimal number',
        ],
        'stock' => [
            'required' => 'Stock is required',
            'integer' => 'Stock must be an integer',
        ],
    ];

    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    /**
     * Check if a cookie exists with the given name.
     *
     * Scope EXCLUDES soft-deleted rows (no `withDeleted()`): the schema's
     * composite UNIQUE on (`tenant_id`, `name`, `deleted_at`) intentionally
     * allows a trashed row's name to be reused by a new active row
     * (closes 06/F1 — `withDeleted()` here contradicted the migration's
     * documented contract).
     *
     * No `LOWER()` wrapper: the `cookies.name` column is collated
     * `utf8mb4_unicode_ci`, which already provides case-insensitive
     * comparison. Wrapping the column in `LOWER()` would force a
     * sequential scan and void the UNIQUE index (closes 06/F6).
     *
     * @param string $name The cookie name to check
     * @return bool True if exists
     */
    public function existsByName(string $name): bool
    {
        return $this->where('name', $name)
            ->countAllResults() > 0;
    }

    /**
     * Check if a cookie exists with the given name, excluding a specific ID.
     *
     * Same scoping and collation rationale as {@see self::existsByName()}.
     *
     * @param string $name The cookie name to check
     * @param int $excludeId The ID to exclude from the search
     * @return bool True if exists
     */
    public function existsByNameExcludingId(string $name, int $excludeId): bool
    {
        return $this->where('name', $name)
            ->where('id !=', $excludeId)
            ->countAllResults() > 0;
    }

    /**
     * Affected-row count from the last UPDATE / DELETE on this model.
     *
     * Hides the leaky `$this->db->affectedRows()` access (06/F11) so the
     * repository talks to the model, not to framework internals. The
     * underlying connection is resolved via the framework helper so the
     * wrapper works under the same test injection paths as the model.
     */
    public function lastAffectedRows(): int
    {
        return $this->db->affectedRows();
    }
}
