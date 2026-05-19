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
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    // Validation rules (database level validation)
    protected $validationRules = [
        'name' => 'required|min_length[3]|max_length[100]',
        'price' => 'required|decimal|greater_than[0]',
        'stock' => 'required|integer|greater_than_equal_to[0]',
        'is_active' => 'in_list[0,1]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Cookie name is required',
            'min_length' => 'Cookie name must be at least 3 characters',
            'max_length' => 'Cookie name cannot exceed 100 characters',
        ],
        'price' => [
            'required' => 'Price is required',
            'decimal' => 'Price must be a valid decimal number',
            'greater_than' => 'Price must be greater than 0',
        ],
        'stock' => [
            'required' => 'Stock is required',
            'integer' => 'Stock must be an integer',
            'greater_than_equal_to' => 'Stock cannot be negative',
        ],
    ];

    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    /**
     * Check if a cookie exists with the given name.
     *
     * Cookie names are reserved after soft delete. This matches the database
     * unique key and preserves historical ERP/audit references.
     *
     * @param string $name The cookie name to check
     * @return bool True if exists
     */
    public function existsByName(string $name): bool
    {
        return $this->withDeleted()
            ->where('LOWER(name)', strtolower($name))
            ->countAllResults() > 0;
    }

    /**
     * Check if a cookie exists with the given name, excluding a specific ID.
     *
     * Used for update operations to allow keeping the same name.
     *
     * @param string $name The cookie name to check
     * @param int $excludeId The ID to exclude from the search
     * @return bool True if exists
     */
    public function existsByNameExcludingId(string $name, int $excludeId): bool
    {
        return $this->withDeleted()
            ->where('LOWER(name)', strtolower($name))
            ->where('id !=', $excludeId)
            ->countAllResults() > 0;
    }
}
