<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\SearchUsers;

/**
 * Query for advanced user search with multiple filter criteria.
 *
 * This query supports filtering by email, role, and status with pagination.
 * All filters are optional and can be combined.
 *
 * Use Cases:
 * - Find all admin users
 * - Find inactive customer accounts
 * - Search for specific email patterns
 * - Export filtered user lists
 *
 * @package App\Domain\User\Queries\SearchUsers
 */
final readonly class SearchUsersQuery
{
    /**
     * @param string|null $email Email filter (partial match)
     * @param string|null $role Role filter (exact match: admin, customer)
     * @param string|null $status Status filter (exact match: active, inactive)
     * @param int $page Current page number (default: 1)
     * @param int $perPage Users per page (default: 20)
     */
    public function __construct(
        public ?string $email = null,
        public ?string $role = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 20
    ) {
    }
}
