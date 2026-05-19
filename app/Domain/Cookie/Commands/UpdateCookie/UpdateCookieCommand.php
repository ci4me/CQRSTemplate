<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

/**
 * Command to update an existing Cookie.
 *
 * Contains all data needed to update a cookie, including the ID
 * of the cookie to update.
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 */
final readonly class UpdateCookieCommand
{
    /**
     * Create a new UpdateCookieCommand.
     *
     * @param int $id The ID of the cookie to update
     * @param string $name The new cookie name (3-100 chars)
     * @param string|null $description The new cookie description
     * @param string $price Decimal price string (for example "2.99")
     * @param int $stock The new stock quantity (must be >= 0)
     * @param bool $isActive Whether the cookie is active
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive
    ) {
    }
}
