<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Domain\Shared\Events\AbstractDomainEvent;

/**
 * Minimal AbstractDomainEvent subclass for the ProcessedEventStoreFlow
 * integration test. Carries only the envelope (no payload) — the flow
 * test does not inspect event fields, only the dispatcher's at-most-once
 * bracket around its listeners.
 */
final readonly class FlowTestEvent extends AbstractDomainEvent
{
    public function __construct(string $eventId)
    {
        parent::__construct(
            eventId: $eventId,
            occurredAt: new \DateTimeImmutable('2026-05-22T00:00:00+00:00'),
            actorId: null,
            aggregateType: 'Flow',
            aggregateId: 'flow-1',
        );
    }
}
