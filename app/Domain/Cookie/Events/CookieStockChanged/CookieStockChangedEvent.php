<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieStockChanged;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Raised when a cookie's stock level changes via a domain operation
 * (decrease/increase). Allows downstream consumers — inventory dashboards,
 * low-stock alerts, replenishment — to react without polling.
 *
 * NOT raised by the bulk-replace path used by UpdateCookieCommand; that
 * already emits CookieUpdatedEvent with previous/new state, which is the
 * higher-resolution signal.
 */
final readonly class CookieStockChangedEvent implements DomainEventInterface
{
    /**
     * __construct.
     */
    public function __construct(
        public ?int $cookieId,
        public int $previousStock,
        public int $newStock,
        public string $reason
    ) {
    }
}
