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
     * @param LoggerInterface $logger PSR-3 logger for the always-on stock-movement audit trail.
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Handle a {@see CookieStockChangedEvent} by appending an audit log line
     * with the previous/new stock and the movement reason.
     */
    public function __invoke(CookieStockChangedEvent $event): void
    {
        $this->logger->info('Cookie stock changed', [
            'domain' => 'Cookie',
            'event' => 'CookieStockChangedEvent',
            'event_id' => $event->eventId,
            'cookie_id' => $event->cookieId,
            'previous_stock' => $event->previousStock,
            'new_stock' => $event->newStock,
            'reason' => $event->reason,
        ]);
    }
}
