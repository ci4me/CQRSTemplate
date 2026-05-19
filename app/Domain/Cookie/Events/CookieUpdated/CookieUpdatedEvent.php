<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieUpdated;

/**
 * Event fired when a Cookie is updated.
 *
 * This event is dispatched whenever any cookie attribute changes,
 * including name, description, price, stock, or active status.
 *
 * @package App\Domain\Cookie\Events\CookieUpdated
 */
final readonly class CookieUpdatedEvent
{
    /**
     * Create a new CookieUpdatedEvent.
     *
     * @param int $cookieId The ID of the updated cookie
     * @param string $cookieName The updated cookie name
     * @param string $cookiePrice Decimal price string for the updated cookie
     */
    public function __construct(
        public int $cookieId,
        public string $cookieName,
        public string $cookiePrice
    ) {
    }
}
