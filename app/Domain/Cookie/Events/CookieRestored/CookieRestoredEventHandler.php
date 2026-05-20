<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieRestored;

use Psr\Log\LoggerInterface;

/**
 * Default handler for {@see CookieRestoredEvent}: writes a structured audit
 * line. Mirrors the other Cookie-event handlers so subscribers (e.g. the
 * read-model projection) get a uniform pipeline.
 */
final readonly class CookieRestoredEventHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CookieRestoredEvent $event): void
    {
        $this->logger->info('Cookie restored', [
            'domain' => 'Cookie',
            'event' => 'CookieRestoredEvent',
            'cookie_id' => $event->cookieId,
            'restored_by' => $event->restoredBy,
            'restored_at' => $event->restoredAt,
        ]);
    }
}
