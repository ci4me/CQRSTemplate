<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieUpdated;

use Psr\Log\LoggerInterface;

/**
 * Event Handler for CookieUpdatedEvent.
 *
 * Logs cookie updates for audit trail and debugging.
 *
 * Responsibilities:
 * - Log cookie updates with changed fields
 * - Can be extended for additional actions:
 *   - Send notification emails if price changed
 *   - Update product catalog cache
 *   - Trigger reindexing
 *   - Notify subscribed customers
 *
 * Usage:
 * This handler is automatically registered via CookieServiceProvider.
 * It's invoked whenever a CookieUpdatedEvent is dispatched.
 *
 * @package App\Domain\Cookie\Events\CookieUpdated
 */
final readonly class CookieUpdatedEventHandler
{
    /**
     * Constructor for CookieUpdatedEventHandler.
     *
     * @param LoggerInterface $logger PSR-3 logger for structured logging
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle the CookieUpdatedEvent.
     *
     * @param CookieUpdatedEvent $event The event containing cookie update data
     * @return void
     */
    public function __invoke(CookieUpdatedEvent $event): void
    {
        $this->logger->info('Cookie updated', [
            'domain' => 'Cookie',
            'event' => 'CookieUpdatedEvent',
            'cookie_id' => $event->cookieId,
            'cookie_name' => $event->cookieName,
            'price' => $event->cookiePrice,
        ]);

        // Future extensions could include:
        // - Compare old vs new price for price change alerts
        // - Clear specific cookie cache
        // - Update search index
        // - Send email if significant price change
    }
}
