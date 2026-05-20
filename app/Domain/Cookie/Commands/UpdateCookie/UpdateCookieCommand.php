<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to update an existing Cookie.
 *
 * Carries the {@see Actor} that initiated the update so the repository
 * can stamp the audit `updated_by` column, and optionally the client's
 * `expectedVersion` so the handler can short-circuit a concurrent-write
 * race BEFORE incurring the cost of loading + mutating the entity.
 *
 * Optimistic-locking contract:
 *  - If `expectedVersion` is null (legacy callers), the handler skips
 *    the pre-flight check and relies on the repository's WHERE version=?
 *    UPDATE to detect concurrent modification.
 *  - If non-null, the handler compares to the loaded entity's version
 *    before issuing the UPDATE and throws a domain-level
 *    concurrent-modification exception when they don't match.
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 */
final readonly class UpdateCookieCommand
{
    /**
     * __construct.
     *
     * @param int         $id
     * @param string      $name
     * @param string|null $description
     * @param string      $price
     * @param int         $stock
     * @param bool        $isActive
     * @param Actor       $updatedBy
     * @param int|null    $expectedVersion
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive,
        public Actor $updatedBy,
        public ?int $expectedVersion = null
    ) {
    }
}
