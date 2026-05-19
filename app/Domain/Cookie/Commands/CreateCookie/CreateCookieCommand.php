<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

/**
 * Command to create a new Cookie.
 *
 * Commands represent the INTENT to perform an action.
 * This command contains all data needed to create a cookie.
 *
 * Commands are:
 * - Immutable DTOs (Data Transfer Objects)
 * - Named in imperative (CreateCookie, not CookieCreated)
 * - Validated by their handlers
 * - Do not contain business logic
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 */
final readonly class CreateCookieCommand
{
    /**
     * Create a new CreateCookieCommand.
     *
     * @param string $name The cookie name (3-100 chars)
     * @param string|null $description The cookie description
     * @param string $price Decimal price string (for example "2.99")
     * @param int $stock The initial stock quantity (must be >= 0)
     * @param bool $isActive Whether the cookie is active
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive = true
    ) {
    }
}
