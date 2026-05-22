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
 * E08 introduced an absolute upper bound on the response size
 * ({@see MAX_RESULTS}) — closes 04/F2. An unbounded findAll() is a
 * footgun on any non-trivial table; callers that genuinely need to
 * iterate every row should use the paginated query instead.
 *
 * @package App\Domain\Cookie\Queries\GetAllCookies
 */
final readonly class GetAllCookiesQuery
{
    /**
     * Hard upper bound on result-set size. The handler asserts the
     * repository did not return more than this many rows; an overrun
     * throws so the abuse is loud rather than silent. 1000 was chosen
     * to comfortably fit the typical product-catalog "load everything
     * to populate a dropdown" use case while still rejecting a runaway
     * `SELECT * FROM cookies` against a table that grew past expectations.
     */
    public const int MAX_RESULTS = 1000;

    /**
     * @param bool $includeInactive Whether to include inactive cookies.
     */
    public function __construct(
        public bool $includeInactive = false
    ) {
    }
}
