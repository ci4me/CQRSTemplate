<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieCreated;

use Psr\Log\LoggerInterface;

/**
 * Event Handler for CookieCreatedEvent.
 *
 * Logs cookie creation for audit trail and debugging.
 *
 * Responsibilities:
 * - Log cookie creation with details (name, price, stock)
 * - Can be extended for additional actions:
 *   - Send notification emails
 *   - Update analytics
 *   - Clear caches
 *   - Trigger webhooks
 *
 * Usage:
 * This handler is automatically registered via CookieServiceProvider.
 * It's invoked whenever a CookieCreatedEvent is dispatched.
 *
 * @package App\Domain\Cookie\Events\CookieCreated
 */
final readonly class CookieCreatedEventHandler
{
    /**
     * Constructor for CookieCreatedEventHandler.
     *
     * @param LoggerInterface $logger PSR-3 logger for structured logging
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle the CookieCreatedEvent.
     *
     * @param CookieCreatedEvent $event The event containing cookie creation data
     * @return void
     */
    public function __invoke(CookieCreatedEvent $event): void
    {
        $this->logger->info('Cookie created', [
            'domain' => 'Cookie',
            'event' => 'CookieCreatedEvent',
            'cookie_id' => $event->cookieId,
            'cookie_name' => $event->cookieName,
            'price' => $event->cookiePrice,
            'initial_stock' => $event->initialStock,
        ]);

        // Future extensions could include:
        // - Email notification to admin
        // - Publish to event stream
        // - Update search index
        // - Clear homepage cache
    }
}
