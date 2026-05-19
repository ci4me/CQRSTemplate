<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Outbox\EventOutboxRelay;
use App\Infrastructure\Outbox\EventOutboxWriter;
use Config\Database;
use Psr\Log\NullLogger;
use Tests\Support\IntegrationTestCase;

final class EventOutboxTest extends IntegrationTestCase
{
    public function test_writer_persists_event_with_serialised_payload(): void
    {
        $writer = new EventOutboxWriter();
        $event = new OutboxTestEvent(7, 'hello');

        $writer->append($event, 'TestAggregate', 7);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('TestAggregate', $row['aggregate_type']);
        $this->assertSame('7', $row['aggregate_id']);
        $this->assertSame(OutboxTestEvent::class, $row['event_class']);
        $this->assertSame('pending', $row['status']);

        $payload = json_decode($row['payload'], true);
        $this->assertSame(7, $payload['id']);
        $this->assertSame('hello', $payload['note']);
    }

    public function test_relay_delivers_pending_event_to_dispatcher(): void
    {
        $writer = new EventOutboxWriter();
        $writer->append(new OutboxTestEvent(1, 'first'), 'TestAggregate', 1);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(OutboxTestEvent::class, static function (OutboxTestEvent $e) use (&$received): void {
            $received[] = $e->note;
        });

        $relay = new EventOutboxRelay($dispatcher, new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(['first'], $received);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('delivered', $row['status']);
        $this->assertNotNull($row['delivered_at']);
    }

    public function test_relay_retries_when_listener_throws(): void
    {
        $writer = new EventOutboxWriter();
        $writer->append(new OutboxTestEvent(1, 'boom'), 'TestAggregate', 1);

        $dispatcher = $this->createDispatcherWithFailingListener();

        $relay = new EventOutboxRelay($dispatcher, new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(0, $stats['delivered']);
        $this->assertSame(1, $stats['retried']);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertStringContainsString('listener exploded', (string) $row['last_error']);
    }

    public function test_relay_does_not_pick_up_rows_not_yet_available(): void
    {
        $writer = new EventOutboxWriter();
        $writer->append(
            new OutboxTestEvent(1, 'future'),
            'TestAggregate',
            1,
            (new \DateTimeImmutable())->modify('+10 minutes')
        );

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(0, $stats['processed']);
    }

    public function test_relay_marks_failed_after_max_attempts(): void
    {
        $writer = new EventOutboxWriter();
        $writer->append(new OutboxTestEvent(1, 'never-works'), 'TestAggregate', 1);

        // Bump attempts to one short of the max so the next failure fails-out.
        Database::connect()->table('event_outbox')->update(['attempts' => 5]);

        $dispatcher = $this->createDispatcherWithFailingListener();
        $relay = new EventOutboxRelay($dispatcher, new NullLogger());

        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
    }

    private function createDispatcherWithFailingListener(): EventDispatcher
    {
        // EventDispatcher catches listener exceptions internally — for the
        // relay's retry path we need the dispatch CALL itself to throw, so
        // we subclass to wire a guaranteed failure.
        return new class extends EventDispatcher {
            // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
            public function dispatch(object $event): void
            {
                throw new \RuntimeException('listener exploded');
            }
        };
    }
}
