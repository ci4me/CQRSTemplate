<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetAllCookies;

/**
 * Query to retrieve all non-deleted Cookies.
 *
 * Excludes inactive cookies by default; pass `includeInactive: true` to
 * include rows with is_active = false. Soft-deleted rows (deleted_at set)
 * are never returned.
 *
 * @package App\Domain\Cookie\Queries\GetAllCookies
 */
final readonly class GetAllCookiesQuery
{
    /**
     * Hard ceiling on rows an unpaginated read may hydrate (round-4 R2).
     *
     * GetAllCookies exists for bounded reference data (dropdowns, exports of
     * small catalogues). ERP-scale lists MUST use GetCookiesPaginatedQuery;
     * this constant is the safety net the repositories enforce via LIMIT.
     */
    public const int MAX_RESULTS = 1000;

    /**
     * Create a new GetAllCookiesQuery.
     *
     * @param bool $includeInactive Whether to include inactive cookies
     */
    public function __construct(
        public bool $includeInactive = false
    ) {
    }
}
