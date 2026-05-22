<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieDeactivated;

use Psr\Log\LoggerInterface;

/**
 * Default handler for {@see CookieDeactivatedEvent}: writes a structured
 * audit line. Mirrors the other Cookie-event handlers so subscribers
 * (e.g. the read-model projection) get a uniform pipeline.
 */
final readonly class CookieDeactivatedEventHandler
{
    /**
     * @param LoggerInterface $logger PSR-3 logger for the always-on audit trail.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a {@see CookieDeactivatedEvent} by appending an audit log line.
     */
    public function __invoke(CookieDeactivatedEvent $event): void
    {
        $this->logger->info('Cookie deactivated', [
            'domain' => 'Cookie',
            'event' => 'CookieDeactivatedEvent',
            'event_id' => $event->eventId,
            'cookie_id' => $event->cookieId,
            'deactivated_by' => $event->actorId,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
