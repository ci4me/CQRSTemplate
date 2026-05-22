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
    /**
     * Inject the PSR-3 logger used for the audit-trail emission.
     *
     * @param LoggerInterface $logger PSR-3 destination; structured records land here.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Write a structured audit log line for the restore.
     *
     * Subscribers should use the event itself for business reactions
     * (e.g. read-model rehydration); this default handler is the always-on
     * audit signal so a restored cookie always shows up in the operations log.
     */
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
