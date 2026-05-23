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
     * Construct a new UpdateCookieCommand.
     *
     * @param int     $id              Target cookie id; must exist and not be soft-deleted.
     * @param string  $name            New display name; validated by CookieName at the handler boundary.
     * @param ?string $description     New long-form description (null to clear).
     * @param string  $price           New decimal-string sale price; converted to CookiePrice by the handler.
     * @param int     $stock           New on-hand quantity (>= 0).
     * @param bool    $isActive        Whether the row stays published after the update.
     * @param Actor   $updatedBy       Audit-trail actor; stamps `updated_by`.
     * @param ?int    $expectedVersion Optional optimistic-lock anchor; see class docblock for the contract.
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
