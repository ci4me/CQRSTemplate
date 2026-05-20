<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to update an existing Cookie.
 *
 * Carries the {@see Actor} that initiated the update so the repository
 * can stamp the audit `updated_by` column.
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 */
final readonly class UpdateCookieCommand
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive,
        public Actor $updatedBy
    ) {
    }
}
