<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookieById;

/**
 * Query to retrieve a single Cookie by ID.
 *
 * Queries represent requests for DATA without changing state.
 * They:
 * - Are named as questions (GetCookieById, not FetchCookie)
 * - Contain only filtering parameters
 * - NEVER modify state (read-only)
 * - Return domain entities or DTOs
 *
 * @package App\Domain\Cookie\Queries\GetCookieById
 */
final readonly class GetCookieByIdQuery
{
    /**
     * Create a new GetCookieByIdQuery.
     *
     * @param int $id The ID of the cookie to retrieve
     */
    public function __construct(
        public int $id
    ) {
    }
}
