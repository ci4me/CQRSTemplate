<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieActivated;

use Psr\Log\LoggerInterface;

/**
 * Default handler for {@see CookieActivatedEvent}: writes a structured
 * audit line. Mirrors the other Cookie-event handlers so subscribers
 * (e.g. the read-model projection) get a uniform pipeline.
 */
final readonly class CookieActivatedEventHandler
{
    /**
     * @param LoggerInterface $logger PSR-3 logger for the always-on audit trail.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a {@see CookieActivatedEvent} by appending an audit log line.
     */
    public function __invoke(CookieActivatedEvent $event): void
    {
        $this->logger->info('Cookie activated', [
            'domain' => 'Cookie',
            'event' => 'CookieActivatedEvent',
            'event_id' => $event->eventId,
            'cookie_id' => $event->cookieId,
            'activated_by' => $event->actorId,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
