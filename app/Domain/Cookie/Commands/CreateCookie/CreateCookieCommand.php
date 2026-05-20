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
     * __construct.
     *
     * @param string      $name
     * @param string|null $description
     * @param string      $price
     * @param int         $stock
     * @param Actor       $createdBy
     * @param bool        $isActive
     * @todo Auto-generated docblock — review and replace this description.
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
