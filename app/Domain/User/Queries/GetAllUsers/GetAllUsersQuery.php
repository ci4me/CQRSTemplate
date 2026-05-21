<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetAllUsers;

/**
 * Query to retrieve a paginated list of users with optional filtering.
 *
 * This query supports pagination and basic filtering for user listings.
 * For advanced filtering, use SearchUsersQuery instead.
 *
 * Query Parameters:
 * - page: Current page number (1-based)
 * - perPage: Number of users per page
 * - includeInactive: Whether to include soft-deleted users
 * - searchTerm: Optional text search across name and email
 *
 * @package App\Domain\User\Queries\GetAllUsers
 */
final readonly class GetAllUsersQuery
{
    private const int DEFAULT_PAGE = 1;
    private const int DEFAULT_PER_PAGE = 20;
    private const int MAX_PER_PAGE = 100;

    public int $page;
    public int $perPage;
    public bool $includeInactive;
    public string $searchTerm;

    /**
     * @param int    $page            Current page number (default: 1)
     * @param int    $perPage         Users per page (default: 20)
     * @param bool   $includeInactive Include deleted users (default: false)
     * @param string $searchTerm      Optional search term (default: '')
     */
    public function __construct(
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE,
        bool $includeInactive = false,
        string $searchTerm = ''
    ) {
        $this->page = max(self::DEFAULT_PAGE, $page);
        $this->perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $this->includeInactive = $includeInactive;
        $this->searchTerm = trim($searchTerm);
    }
}
