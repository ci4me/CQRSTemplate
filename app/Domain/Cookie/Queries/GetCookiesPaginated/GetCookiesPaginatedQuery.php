<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookiesPaginated;

use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Query to retrieve paginated Cookies with optional search.
 *
 * Supports:
 * - Pagination (page, perPage)
 * - Search by name (searchTerm)
 * - Filter by active status
 *
 * @package App\Domain\Cookie\Queries\GetCookiesPaginated
 */
final readonly class GetCookiesPaginatedQuery
{
    private const int DEFAULT_PAGE = 1;
    private const int DEFAULT_PER_PAGE = 20;
    private const int MAX_PER_PAGE = 100;

    /**
     * Page ceiling (round-4 R2): the page number had a floor but no upper
     * bound, so one querystring parameter could force a deep-OFFSET scan
     * (page=10^9 -> OFFSET 2*10^10). Out-of-range pages throw instead of
     * silently clamping so client bugs surface.
     */
    public const int MAX_PAGE = 10000;

    public int $page;
    public int $perPage;
    public ?string $searchTerm;
    public bool $includeInactive;

    /**
     * Create a new GetCookiesPaginatedQuery.
     *
     * @param int         $page            The page number (1-indexed, <= MAX_PAGE)
     * @param int         $perPage         Number of items per page
     * @param string|null $searchTerm      Optional search term for name filtering
     * @param bool        $includeInactive Whether to include inactive cookies
     * @throws ValidationException When the page exceeds MAX_PAGE
     */
    public function __construct(
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ) {
        if ($page > self::MAX_PAGE) {
            throw ValidationException::outOfRange('page', self::DEFAULT_PAGE, self::MAX_PAGE, $page);
        }

        $this->page = max(self::DEFAULT_PAGE, $page);
        $this->perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $this->searchTerm = $searchTerm !== null ? trim($searchTerm) : null;
        $this->includeInactive = $includeInactive;
    }
}
