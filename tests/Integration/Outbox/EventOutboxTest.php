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

        // SV-1: payload column carries a versioned envelope. Event body lives
        // under the inner `payload` key, not at the JSON root.
        $envelope = json_decode($row['payload'], true);
        $this->assertSame(EventOutboxWriter::SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame(OutboxTestEvent::class, $envelope['event_class']);
        $this->assertSame(7, $envelope['payload']['id']);
        $this->assertSame('hello', $envelope['payload']['note']);
    }

    public function test_writer_envelope_contains_schema_version_event_class_occurred_at_and_correlation_id(): void
    {
        \App\Infrastructure\Logging\CorrelationIdService::set('test-correlation-abc');

        $writer = new EventOutboxWriter();
        $writer->append(new OutboxTestEvent(42, 'envelope-check'), 'TestAggregate', 42);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $envelope = json_decode($row['payload'], true);

        $this->assertSame(1, $envelope['schema_version']);
        $this->assertSame(OutboxTestEvent::class, $envelope['event_class']);
        $this->assertSame('test-correlation-abc', $envelope['correlation_id']);
        $this->assertArrayHasKey('occurred_at', $envelope);
        // occurred_at must be ISO 8601 / ATOM so downstream consumers don't
        // have to guess at the format.
        $this->assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $envelope['occurred_at'])
        );
        // Inner payload is the event body, kept separate from the envelope's
        // own metadata. This is the boundary that lets us evolve the
        // envelope without rewriting events.
        $this->assertSame(['id' => 42, 'note' => 'envelope-check'], $envelope['payload']);
    }

    public function test_relay_delivers_v1_envelope_round_trip(): void
    {
        // Writer emits the v1 envelope; relay must unwrap it and rehydrate
        // the same event instance shape on the other side.
        $writer = new EventOutboxWriter();
        $writer->append(new OutboxTestEvent(99, 'roundtrip'), 'TestAggregate', 99);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(OutboxTestEvent::class, static function (OutboxTestEvent $e) use (&$received): void {
            $received[] = ['id' => $e->id, 'note' => $e->note];
        });

        $relay = new EventOutboxRelay($dispatcher, new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['delivered']);
        $this->assertSame([['id' => 99, 'note' => 'roundtrip']], $received);
    }

    public function test_relay_processes_legacy_payload_without_envelope(): void
    {
        // Backward-compat regression guard: outbox rows written before SV-1
        // landed have the event body at the JSON root, with no
        // `schema_version` key. The relay must still rehydrate + dispatch
        // them so deploying SV-1 does not strand pending rows.
        $legacyBody = json_encode(['id' => 1, 'note' => 'legacy-row']);
        Database::connect()->table('event_outbox')->insert([
            'aggregate_type' => 'TestAggregate',
            'aggregate_id' => '1',
            'event_class' => OutboxTestEvent::class,
            'payload' => $legacyBody,
            'correlation_id' => 'legacy-correlation',
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'available_at' => date('Y-m-d H:i:s'),
            'occurred_at' => date('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(OutboxTestEvent::class, static function (OutboxTestEvent $e) use (&$received): void {
            $received[] = $e->note;
        });

        $relay = new EventOutboxRelay($dispatcher, new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(['legacy-row'], $received);

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('delivered', $row['status']);
    }

    public function test_relay_marks_unsupported_schema_and_does_not_dispatch(): void
    {
        // A row written by a NEWER writer (schema_version > current binary)
        // must NOT be dispatched. The relay parks it under a terminal
        // `unsupported_schema` status so operators can audit and replay it
        // after upgrading the binary.
        $futureEnvelope = json_encode([
            'schema_version' => EventOutboxWriter::SCHEMA_VERSION + 1,
            'event_class' => OutboxTestEvent::class,
            'occurred_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'correlation_id' => 'future-row',
            'payload' => ['id' => 1, 'note' => 'too-new'],
        ]);
        Database::connect()->table('event_outbox')->insert([
            'aggregate_type' => 'TestAggregate',
            'aggregate_id' => '1',
            'event_class' => OutboxTestEvent::class,
            'payload' => $futureEnvelope,
            'correlation_id' => 'future-row',
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'available_at' => date('Y-m-d H:i:s'),
            'occurred_at' => date('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(OutboxTestEvent::class, static function (OutboxTestEvent $e) use (&$received): void {
            $received[] = $e->note;
        });

        $relay = new EventOutboxRelay($dispatcher, new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(0, $stats['delivered']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $received, 'Unsupported-schema rows must not be dispatched');

        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('unsupported_schema', $row['status']);
        $this->assertStringContainsString('schema_version', (string) $row['last_error']);
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
