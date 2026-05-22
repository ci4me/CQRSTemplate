<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieStockChanged;

use Psr\Log\LoggerInterface;

/**
 * Logs every stock movement. ERP downstream consumers (low-stock alerts,
 * replenishment jobs, inventory dashboards) can subscribe to the event
 * directly; this handler is the always-on audit trail.
 */
final readonly class CookieStockChangedEventHandler
{
    /**
     * Inject the PSR-3 logger used for the always-on stock-movement audit line.
     *
     * @param LoggerInterface $logger PSR-3 destination; one structured record per stock change.
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Write a structured "stock changed" audit log entry.
     *
     * Business reactions (low-stock alerts, replenishment jobs, inventory
     * dashboards) should subscribe to the event directly; this default
     * handler is the always-on audit trail and never short-circuits.
     */
    public function __invoke(CookieStockChangedEvent $event): void
    {
        $this->logger->info('Cookie stock changed', [
            'domain' => 'Cookie',
            'event' => 'CookieStockChangedEvent',
            'cookie_id' => $event->cookieId,
            'previous_stock' => $event->previousStock,
            'new_stock' => $event->newStock,
            'reason' => $event->reason,
        ]);
    }
}
