<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\AdjustCookieStock;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to adjust a cookie's on-hand stock by a signed delta.
 *
 * Added in round-4 R3 (E21 adjudication): the entity's stock mutators and
 * CookieStockChangedEvent existed but were UNREACHABLE — no command drove
 * them, so the inventory-movement pattern every ERP domain needs had no
 * executable reference. This command completes the flow:
 *
 *   AdjustCookieStockCommand -> AdjustCookieStockHandler
 *     -> Cookie::increaseStock()/decreaseStock() (raises CookieStockChangedEvent)
 *     -> repository save() (drains outbox-first, same transaction)
 *
 * Positive `adjustment` receives stock; negative issues it. Zero is
 * rejected by the handler — a no-op movement is a caller bug.
 *
 * @package App\Domain\Cookie\Commands\AdjustCookieStock
 */
final readonly class AdjustCookieStockCommand
{
    /**
     * Construct a new AdjustCookieStockCommand.
     *
     * @param int    $id         Target cookie id; must exist and not be soft-deleted.
     * @param int    $adjustment Signed stock delta (> 0 receives, < 0 issues; never 0).
     * @param string $reason     Business reason for the movement ("sale",
     *                           "restock_received", "stocktake_correction", ...).
     * @param Actor  $adjustedBy Audit-trail actor; stamps `updated_by`.
     */
    public function __construct(
        public int $id,
        public int $adjustment,
        public string $reason,
        public Actor $adjustedBy
    ) {
    }
}
