<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to create a new Cookie.
 *
 * Carries the {@see Actor} that initiated the create so the repository
 * can stamp the audit `created_by` column. Controllers resolve the
 * actor via {@see \App\Infrastructure\Auth\Services\ActorResolver}.
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 */
final readonly class CreateCookieCommand
{
    /**
     * Construct a new CreateCookieCommand.
     *
     * @param string  $name        Cookie display name; trimmed and validated by CookieName at the entity boundary.
     * @param ?string $description Optional long-form description rendered on the catalog page.
     * @param string  $price       Decimal-string sale price; converted to CookiePrice (minor units) by the handler.
     * @param int     $stock       Initial on-hand quantity (>= 0).
     * @param Actor   $createdBy   Audit-trail actor; stamps `created_by`. NEVER null on HTTP-originated commands.
     * @param bool    $isActive    Whether the row is published at create time (defaults to true).
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public Actor $createdBy,
        public bool $isActive = true
    ) {
    }
}
