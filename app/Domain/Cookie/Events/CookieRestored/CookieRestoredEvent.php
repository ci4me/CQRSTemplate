<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieRestored;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Dispatched after a soft-deleted cookie is brought back from the trash.
 */
final readonly class CookieRestoredEvent implements DomainEventInterface
{
    /**
     * __construct.
     */
    public function __construct(
        public int $cookieId,
        public int $restoredBy,
        public string $restoredAt
    ) {
    }
}
