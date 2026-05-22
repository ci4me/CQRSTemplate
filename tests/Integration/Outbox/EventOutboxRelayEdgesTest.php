<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Outbox\EventOutboxRelay;
use Config\Database;
use Psr\Log\NullLogger;
use Tests\Support\IntegrationTestCase;

/**
 * Edge-case coverage for the relay: malformed envelopes, hostile class names,
 * missing constructor params, and the claim() race-loss path.
 */
final class EventOutboxRelayEdgesTest extends IntegrationTestCase
{
    public function test_relay_marks_failed_when_payload_is_not_json(): void
    {
        $this->insertRow('not-json{at-all', OutboxTestEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('payload decode failed', (string) $row['last_error']);
    }

    public function test_relay_marks_failed_when_envelope_payload_is_not_an_array(): void
    {
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => OutboxTestEvent::class,
            'payload' => 'not-an-array',
        ]);
        $this->insertRow((string) $envelope, OutboxTestEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
    }

    public function test_relay_marks_failed_when_schema_version_is_not_int(): void
    {
        $envelope = json_encode([
            'schema_version' => 'one',
            'event_class' => OutboxTestEvent::class,
            'payload' => ['id' => 1, 'note' => 'x'],
        ]);
        $this->insertRow((string) $envelope, OutboxTestEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
    }

    public function test_relay_marks_failed_when_event_class_is_not_a_string(): void
    {
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => 123,
            'payload' => ['id' => 1, 'note' => 'x'],
        ]);
        $this->insertRow((string) $envelope, OutboxTestEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
    }

    public function test_relay_refuses_to_rehydrate_class_that_does_not_exist(): void
    {
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => 'App\\Nonsense\\GhostEvent',
            'payload' => ['id' => 1],
        ]);
        $this->insertRow((string) $envelope, 'App\\Nonsense\\GhostEvent');

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertStringContainsString('rehydrate failed', (string) $row['last_error']);
    }

    public function test_relay_refuses_to_rehydrate_class_that_is_not_a_domain_event(): void
    {
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => NotAnEvent::class,
            'payload' => [],
        ]);
        $this->insertRow((string) $envelope, NotAnEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertStringContainsString('DomainEventInterface', (string) $row['last_error']);
    }

    public function test_relay_marks_failed_when_payload_missing_required_parameter(): void
    {
        // OutboxTestEvent requires `id` AND `note`. Provide only `id`.
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => OutboxTestEvent::class,
            'payload' => ['id' => 1],
        ]);
        $this->insertRow((string) $envelope, OutboxTestEvent::class);

        $relay = new EventOutboxRelay(new EventDispatcher(), new NullLogger());
        $stats = $relay->drain();

        $this->assertSame(1, $stats['failed']);
        $row = Database::connect()->table('event_outbox')->get()->getRowArray();
        $this->assertStringContainsString('missing required parameter', (string) $row['last_error']);
    }

    public function test_relay_rehydrates_event_with_no_constructor_and_default_parameter(): void
    {
        $envelope = json_encode([
            'schema_version' => 1,
            'event_class' => NoConstructorEvent::class,
            'payload' => [],
        ]);
        $this->insertRow((string) $envelope, NoConstructorEvent::class);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(NoConstructorEvent::class, static function (NoConstructorEvent $e) use (&$received): void {
            $received[] = $e::class;
        });
        $relay = new EventOutboxRelay($dispatcher, new NullLogger());

        $stats = $relay->drain();
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame([NoConstructorEvent::class], $received);

        // Now an event that has a default-valued parameter not present in the payload:
        Database::connect()->table('event_outbox')->emptyTable();
        $envelope2 = json_encode([
            'schema_version' => 1,
            'event_class' => DefaultParamEvent::class,
            'payload' => ['id' => 7],
        ]);
        $this->insertRow((string) $envelope2, DefaultParamEvent::class);
        $defaults = [];
        $dispatcher2 = new EventDispatcher();
        $dispatcher2->subscribe(DefaultParamEvent::class, static function (DefaultParamEvent $e) use (&$defaults): void {
            $defaults[] = $e->note;
        });
        $relay2 = new EventOutboxRelay($dispatcher2, new NullLogger());
        $stats2 = $relay2->drain();
        $this->assertSame(1, $stats2['delivered']);
        $this->assertSame(['default-note'], $defaults);
    }

    public function test_relay_skips_rows_already_in_flight(): void
    {
        // fetchPending filters on status = 'pending', so a row that another
        // worker already claimed (status = in_flight) must not be touched.
        $this->insertRow(
            (string) json_encode([
                'schema_version' => 1,
                'event_class' => OutboxTestEvent::class,
                'payload' => ['id' => 1, 'note' => 'race'],
            ]),
            OutboxTestEvent::class,
        );
        Database::connect()
            ->table('event_outbox')
            ->where('id >', 0)
            ->update(['status' => 'in_flight']);

        $stats = (new EventOutboxRelay(new EventDispatcher(), new NullLogger()))->drain();

        $this->assertSame(0, $stats['processed']);
    }

    private function insertRow(string $payload, string $eventClass): void
    {
        Database::connect()->table('event_outbox')->insert([
            'aggregate_type' => 'TestAggregate',
            'aggregate_id' => '1',
            'event_class' => $eventClass,
            'payload' => $payload,
            'correlation_id' => 'edge-test',
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'available_at' => date('Y-m-d H:i:s'),
            'occurred_at' => date('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);
    }
}
