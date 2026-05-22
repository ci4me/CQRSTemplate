<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Events\CookieActivated;

use App\Domain\Cookie\Events\CookieActivated\CookieActivatedEvent;
use App\Domain\Cookie\Events\CookieActivated\CookieActivatedEventHandler;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\DomainEventInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

/**
 * Per-event shape tests for {@see CookieActivatedEvent} + its handler.
 *
 * Mirrors the structure used by the other Cookie-event tests (envelope
 * contract pinned via inheritance, payload + jsonSerialize round trip,
 * handler logs the expected audit context).
 */
final class CookieActivatedEventTest extends UnitTestCase
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
        $this->assertSame('5', $event->aggregateId);
    }

    public function test_event_carries_envelope_and_payload(): void
    {
        $event = new CookieActivatedEvent(
            eventId: 'evt-act-1',
            occurredAt: $this->now,
            actorId: 42,
            cookieId: 5,
        );

        $this->assertSame('evt-act-1', $event->eventId);
        $this->assertSame(42, $event->actorId);
        $this->assertSame(5, $event->cookieId);
    }

    public function test_event_json_serialize_merges_envelope_and_payload(): void
    {
        $event = $this->makeEvent();

        $decoded = $event->jsonSerialize();

        $this->assertSame('evt-act-1', $decoded['eventId']);
        $this->assertSame('Cookie', $decoded['aggregateType']);
        $this->assertSame('5', $decoded['aggregateId']);
        $this->assertSame(5, $decoded['cookieId']);
        $this->assertNull($decoded['actorId']);
    }

    public function test_handler_logs_with_audit_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Cookie activated', $this->callback(function (array $ctx): bool {
                return $ctx['domain'] === 'Cookie'
                    && $ctx['event'] === 'CookieActivatedEvent'
                    && $ctx['event_id'] === 'evt-act-1'
                    && $ctx['cookie_id'] === 5
                    && $ctx['activated_by'] === null
                    && $ctx['occurred_at'] === '2026-05-22T10:00:00+00:00';
            }));

        (new CookieActivatedEventHandler($logger))($this->makeEvent());
    }

    public function test_handler_does_not_throw_with_real_logger(): void
    {
        $logger = LoggerFactory::create('test.cookie.events');
        $handler = new CookieActivatedEventHandler($logger);

        $handler($this->makeEvent());

        $this->assertTrue(true);
    }

    private function makeEvent(): CookieActivatedEvent
    {
        return new CookieActivatedEvent(
            eventId: 'evt-act-1',
            occurredAt: $this->now,
            actorId: null,
            cookieId: 5,
        );
    }
}
