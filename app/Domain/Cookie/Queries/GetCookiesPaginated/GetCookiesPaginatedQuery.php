<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookiesPaginated;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Query to retrieve paginated Cookies with optional search.
 *
 * Supports:
 * - Pagination (page, perPage)
 * - Search by name (searchTerm) — length-capped + LIKE-escaped here
 * - Filter by active status
 *
 * E08 hardened the input shape (closes 04/F4, 04/F6):
 *  - `$page` is bounded by {@see MAX_PAGE} (10_000) — attackers cannot
 *    walk the pagination cursor into a denial-of-service `OFFSET` value.
 *  - `$searchTerm` is bounded by {@see MAX_SEARCH_LENGTH} (100 chars)
 *    and gets LIKE-escaped here (the `%`, `_`, and `\` metacharacters
 *    are backslash-escaped) so the repository receives a term that is
 *    safe to interpolate into `LIKE ?` directly. The repository no
 *    longer needs to know about LIKE-escaping (closes T8 leak).
 *
 * @package App\Domain\Cookie\Queries\GetCookiesPaginated
 */
final readonly class GetCookiesPaginatedQuery
{
    private const int DEFAULT_PAGE = 1;
    private const int DEFAULT_PER_PAGE = 20;
    private const int MAX_PER_PAGE = 100;

    /**
     * Hard ceiling on the page number — closes 04/F6.
     *
     * Page * perPage drives the SQL OFFSET. With perPage capped at 100,
     * a MAX_PAGE of 10_000 means the repository can see at most
     * OFFSET 1_000_000, which is still a database-killing scan on a
     * non-indexed cursor. We reject pages beyond this cap with a
     * ValidationException so the abuse stops at the query DTO rather
     * than propagating into the read repository.
     */
    public const int MAX_PAGE = 10_000;

    /**
     * Hard ceiling on the search-term length — closes 04/F4.
     *
     * 100 characters is comfortably longer than any legitimate product
     * name. Anything longer is either a bug (unbounded input from a
     * form that's missing a maxlength attribute) or an attack
     * (constructing a deliberately expensive LIKE pattern).
     */
    public const int MAX_SEARCH_LENGTH = 100;

    public int $page;
    public int $perPage;
    public ?string $searchTerm;
    public bool $includeInactive;

    /**
     * @param int         $page            The page number (1-indexed). Capped at {@see MAX_PAGE}.
     * @param int         $perPage         Number of items per page. Capped at {@see MAX_PER_PAGE}.
     * @param string|null $searchTerm      Optional search term for name filtering.
     *                                     Length-capped at {@see MAX_SEARCH_LENGTH} and LIKE-escaped.
     * @param bool        $includeInactive Whether to include inactive cookies.
     * @throws ValidationException When $page exceeds {@see MAX_PAGE} or $searchTerm exceeds
     *                             {@see MAX_SEARCH_LENGTH}.
     */
    public function __construct(
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ) {
        $page = max(self::DEFAULT_PAGE, $page);
        if ($page > self::MAX_PAGE) {
            throw ValidationException::outOfRange(
                'page',
                self::DEFAULT_PAGE,
                self::MAX_PAGE,
                $page,
                ErrorCodes::COOKIE_QUERY_PAGE_LIMIT_EXCEEDED
            );
        }
        $this->page = $page;
        $this->perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $this->searchTerm = self::normaliseSearchTerm($searchTerm);
        $this->includeInactive = $includeInactive;
    }

    /**
     * Trim → length-cap → LIKE-escape the search term.
     *
     * Returns null for null and empty-after-trim inputs so the
     * repository can short-circuit the WHERE branch.
     *
     * @throws ValidationException When the trimmed term exceeds
     *                             {@see MAX_SEARCH_LENGTH}.
     */
    private static function normaliseSearchTerm(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $length = mb_strlen($trimmed);
        if ($length > self::MAX_SEARCH_LENGTH) {
            throw ValidationException::fieldTooLong(
                'searchTerm',
                self::MAX_SEARCH_LENGTH,
                $length,
                ErrorCodes::COOKIE_QUERY_SEARCH_TERM_TOO_LONG
            );
        }

        // LIKE-escape `%`, `_`, and `\` so the repository can interpolate
        // the term into `LIKE CONCAT('%', ?, '%')` without leaking
        // wildcard semantics to the caller. The backslash MUST be
        // escaped first or the subsequent escapes get double-escaped.
        return addcslashes($trimmed, '\\%_');
    }
}
