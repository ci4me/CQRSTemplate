<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeleted;

/**
 * Event fired when a Cookie is deleted (soft delete).
 *
 * Note: This represents a SOFT delete. The cookie record remains
 * in the database but is marked as deleted.
 *
 * @package App\Domain\Cookie\Events\CookieDeleted
 */
final readonly class CookieDeletedEvent
{
    /**
     * Create a new CookieDeletedEvent.
     *
     * @param int $cookieId The ID of the deleted cookie
     * @param string $cookieName The name of the deleted cookie
     */
    public function __construct(
        public int $cookieId,
        public string $cookieName
    ) {
    }
}
