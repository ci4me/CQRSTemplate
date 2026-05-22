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
     * @param LoggerInterface $logger PSR-3 logger for the always-on audit trail.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a {@see CookieRestoredEvent} by appending an audit log line.
     * The `restored_by` field comes from the envelope's `actorId`; the
     * legacy `restored_at` string field was dropped in favour of the
     * envelope's UTC `occurredAt`.
     */
    public function __invoke(CookieRestoredEvent $event): void
    {
        $this->logger->info('Cookie restored', [
            'domain' => 'Cookie',
            'event' => 'CookieRestoredEvent',
            'event_id' => $event->eventId,
            'cookie_id' => $event->cookieId,
            'restored_by' => $event->actorId,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
