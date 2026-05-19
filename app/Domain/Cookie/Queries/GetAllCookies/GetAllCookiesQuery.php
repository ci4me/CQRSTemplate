<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetAllCookies;

/**
 * Query to retrieve all active Cookies.
 *
 * Returns all cookies that are:
 * - Active (is_active = true)
 * - Not deleted (deleted_at = null)
 *
 * @package App\Domain\Cookie\Queries\GetAllCookies
 */
final readonly class GetAllCookiesQuery
{
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
