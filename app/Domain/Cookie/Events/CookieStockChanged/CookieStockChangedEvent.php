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
     * Construct a CookieStockChangedEvent payload.
     *
     * Captures the BEFORE and AFTER stock levels plus a free-text reason
     * (e.g. "sale", "manual_adjustment", "restock_received") so downstream
     * inventory consumers don't need to diff against the read model.
     *
     * @param ?int   $cookieId      Surrogate id; null only for events emitted by an unpersisted aggregate (rare; tightened in E04).
     * @param int    $previousStock Stock level immediately before the change (>= 0).
     * @param int    $newStock      Stock level immediately after the change (>= 0).
     * @param string $reason        Short identifier categorising the movement.
     */
    public function __construct(
        public ?int $cookieId,
        public int $previousStock,
        public int $newStock,
        public string $reason
    ) {
    }
}
