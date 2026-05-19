<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeleted;

use Psr\Log\LoggerInterface;

/**
 * Event Handler for CookieDeletedEvent.
 *
 * Logs cookie deletion (soft delete) for audit trail and debugging.
 *
 * Responsibilities:
 * - Log cookie soft deletion
 * - Can be extended for additional actions:
 *   - Archive cookie data
 *   - Update inventory system
 *   - Remove from active catalogs
 *   - Clear related caches
 *
 * Usage:
 * This handler is automatically registered via CookieServiceProvider.
 * It's invoked whenever a CookieDeletedEvent is dispatched.
 *
 * Note: This is a SOFT delete event. The cookie record still exists in
 * the database with deleted_at timestamp set.
 *
 * @package App\Domain\Cookie\Events\CookieDeleted
 */
final readonly class CookieDeletedEventHandler
{
    /**
     * Constructor for CookieDeletedEventHandler.
     *
     * @param LoggerInterface $logger PSR-3 logger for structured logging
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle the CookieDeletedEvent.
     *
     * @param CookieDeletedEvent $event The event containing cookie deletion data
     */
    public function __invoke(CookieDeletedEvent $event): void
    {
        $this->logger->info('Cookie deleted (soft delete)', [
            'domain' => 'Cookie',
            'event' => 'CookieDeletedEvent',
            'cookie_id' => $event->cookieId,
            'cookie_name' => $event->cookieName,
            'deletion_type' => 'soft',
        ]);

        // Future extensions could include:
        // - Archive cookie to separate table
        // - Remove from search index
        // - Clear all cookie caches
        // - Update related orders/stats
    }
}
