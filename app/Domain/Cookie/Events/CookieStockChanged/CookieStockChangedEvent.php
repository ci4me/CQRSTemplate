<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieStockChanged;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Raised when a cookie's stock level changes via a domain operation
 * (decrease/increase). Allows downstream consumers — inventory dashboards,
 * low-stock alerts, replenishment — to react without polling.
 *
 * NOT raised by the bulk-replace path used by UpdateCookieCommand; that
 * already emits CookieUpdatedEvent with previous/new state, which is the
 * higher-resolution signal.
 */
final readonly class CookieStockChangedEvent extends AbstractDomainEvent
{
    /**
     * Construct a CookieStockChangedEvent payload.
     *
     * Captures the BEFORE and AFTER stock levels plus a free-text reason
     * (e.g. "sale", "manual_adjustment", "restock_received") so downstream
     * inventory consumers don't need to diff against the read model.
     * Envelope fields (eventId/occurredAt/actorId) come from the parent.
     *
     * `cookieId` is non-nullable as of E04: the entity guards stock
     * mutations with assertPersisted(), so an unpersisted emitter is a
     * programming error, not a payload variant.
     *
     * @param int      $cookieId      Surrogate id of the cookie whose stock moved.
     * @param int      $previousStock Stock level immediately before the change (>= 0).
     * @param int      $newStock      Stock level immediately after the change (>= 0).
     * @param string   $reason        Short identifier categorising the movement.
     * @param int|null $actorId       Actor that caused the movement (null = system)
     */
    public function __construct(
        public int $cookieId,
        public int $previousStock,
        public int $newStock,
        public string $reason,
        ?int $actorId = null
    ) {
        parent::__construct(actorId: $actorId);
    }
}
