<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Stateless lifecycle precondition checks for the Cookie aggregate.
 *
 * Extracted from {@see Cookie} during E07 to keep the entity below the
 * 200-logical-line class-length cap (round-3 audit slice 15/F9). The
 * assertions are pure functions over the inputs the aggregate passes in;
 * there is no shared state, so a separate class with two static methods
 * is the simplest expression. (We deliberately avoid a trait here so
 * the next clone of the Cookie template does not inherit a
 * `*Accessors`-style trait pattern — see slice 01/F8.)
 *
 * Why a class (not a trait):
 *   - Traits hide their composition surface. A failing test that points
 *     at `Cookie::assertNotDeleted()` is less obvious than one that
 *     points at `CookieStateAssertions::ensureNotDeleted()`.
 *   - Methods are static so the entity is not coupled to an instance
 *     and there is nothing to mock — the assertions throw a real
 *     domain exception which is the testable surface anyway.
 *
 * @package App\Domain\Cookie\Entities
 */
final class CookieStateAssertions
{
    private function __construct()
    {
    }

    /**
     * @throws DomainException When the cookie has been soft-deleted.
     */
    public static function ensureNotDeleted(?string $deletedAt): void
    {
        if ($deletedAt !== null) {
            throw DomainException::invalidState(
                'Cookie',
                'cannot mutate a soft-deleted cookie; restore it first',
                ErrorCodes::COOKIE_STATE_DELETED
            );
        }
    }

    /**
     * Returns the non-null id so callers don't need to cast (slice 01/F7).
     *
     * @throws DomainException When the entity has not been persisted yet.
     */
    public static function ensurePersisted(?int $id, string $operation): int
    {
        if ($id === null) {
            throw DomainException::invalidState(
                'Cookie',
                sprintf('%s requires a persisted entity (id is null)', $operation),
                ErrorCodes::COOKIE_STATE_NOT_PERSISTED
            );
        }
        return $id;
    }
}
