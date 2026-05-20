<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieUpdated;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event fired when a Cookie is updated.
 *
 * Carries both the previous and the new public state so audit consumers can
 * record a structured diff without needing to query the database. Fields are
 * limited to scalar/null types to keep the payload serialisable.
 *
 * @package App\Domain\Cookie\Events\CookieUpdated
 */
final readonly class CookieUpdatedEvent implements DomainEventInterface
{
    /**
     * @param int                        $cookieId      ID of the updated cookie
     * @param string                     $cookieName    New name (denormalised for log readability)
     * @param string                     $cookiePrice   New decimal price string
     * @param array<string, scalar|null> $previousState Snapshot before the update
     * @param array<string, scalar|null> $newState      Snapshot after the update
     * @param int                        $updatedBy     Actor id (0 for system)
     */
    public function __construct(
        public int $cookieId,
        public string $cookieName,
        public string $cookiePrice,
        public array $previousState = [],
        public array $newState = [],
        public int $updatedBy = 0
    ) {
    }
}
