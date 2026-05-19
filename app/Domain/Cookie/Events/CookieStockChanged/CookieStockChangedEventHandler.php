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
    public function __construct(private LoggerInterface $logger)
    {
    }

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
