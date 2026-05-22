<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events\CookieDeactivated;

use App\Domain\Cookie\Events\CookieDeactivated\CookieDeactivatedEvent;
use App\Domain\Cookie\Events\CookieDeactivated\CookieDeactivatedEventHandler;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\DomainEventInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

/**
 * Per-event shape tests for {@see CookieDeactivatedEvent} + its handler.
 *
 * Mirrors {@see \Tests\Unit\Domain\Cookie\Events\CookieActivated\CookieActivatedEventTest}.
 */
final class CookieDeactivatedEventTest extends UnitTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-05-22 10:00:00', new \DateTimeZone('UTC'));
    }

    public function test_event_extends_abstract_domain_event(): void
    {
        $event = $this->makeEvent();

        $this->assertInstanceOf(AbstractDomainEvent::class, $event);
        $this->assertInstanceOf(DomainEventInterface::class, $event);
        $this->assertSame('Cookie', $event->aggregateType);
        $this->assertSame('7', $event->aggregateId);
    }

    public function test_event_carries_envelope_and_payload(): void
    {
        $event = new CookieDeactivatedEvent(
            eventId: 'evt-deact-1',
            occurredAt: $this->now,
            actorId: 99,
            cookieId: 7,
        );

        $this->assertSame('evt-deact-1', $event->eventId);
        $this->assertSame(99, $event->actorId);
        $this->assertSame(7, $event->cookieId);
    }

    public function test_event_json_serialize_merges_envelope_and_payload(): void
    {
        $event = $this->makeEvent();

        $decoded = $event->jsonSerialize();

        $this->assertSame('evt-deact-1', $decoded['eventId']);
        $this->assertSame('Cookie', $decoded['aggregateType']);
        $this->assertSame('7', $decoded['aggregateId']);
        $this->assertSame(7, $decoded['cookieId']);
    }

    public function test_handler_logs_with_audit_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Cookie deactivated', $this->callback(function (array $ctx): bool {
                return $ctx['domain'] === 'Cookie'
                    && $ctx['event'] === 'CookieDeactivatedEvent'
                    && $ctx['event_id'] === 'evt-deact-1'
                    && $ctx['cookie_id'] === 7
                    && $ctx['deactivated_by'] === 99
                    && $ctx['occurred_at'] === '2026-05-22T10:00:00+00:00';
            }));

        (new CookieDeactivatedEventHandler($logger))($this->makeEvent());
    }

    public function test_handler_does_not_throw_with_real_logger(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieDeactivatedEventHandler($logger);

        $handler($this->makeEvent());

        $this->assertTrue(true);
    }

    private function makeEvent(): CookieDeactivatedEvent
    {
        return new CookieDeactivatedEvent(
            eventId: 'evt-deact-1',
            occurredAt: $this->now,
            actorId: 99,
            cookieId: 7,
        );
    }
}
