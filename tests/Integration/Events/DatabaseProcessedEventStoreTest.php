<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Infrastructure\Events\DatabaseProcessedEventStore;
use Config\Database;
use Tests\Support\IntegrationTestCase;

/**
 * Adapter-level tests for the round-3 E12.5 handler-side dedup port.
 *
 * Lives under `tests/Integration` because the adapter speaks to a real
 * CI4 database connection. The IntegrationTestCase trait migrates the
 * `processed_events` table into the in-memory SQLite group before each
 * test, so the assertions exercise the actual `INSERT IGNORE`/`SELECT 1`
 * code paths — not a fake.
 */
final class DatabaseProcessedEventStoreTest extends IntegrationTestCase
{
    public function test_is_processed_returns_false_for_an_unseen_pair(): void
    {
        $store = new DatabaseProcessedEventStore();

        $this->assertFalse(
            $store->isProcessed('019000a0-0000-7000-8000-000000000001', 'App\\Listener\\NoOne')
        );
    }

    public function test_mark_then_check_round_trip_returns_true(): void
    {
        $store = new DatabaseProcessedEventStore();
        $eventId = '019000a0-0000-7000-8000-000000000002';
        $listener = 'App\\Listener\\Roundtrip';

        $store->markProcessed($eventId, $listener);

        $this->assertTrue($store->isProcessed($eventId, $listener));
        $this->seeInDatabase('processed_events', [
            'event_id' => $eventId,
            'listener_class' => $listener,
        ]);
    }

    public function test_double_mark_is_a_no_op_and_does_not_throw(): void
    {
        $store = new DatabaseProcessedEventStore();
        $eventId = '019000a0-0000-7000-8000-000000000003';
        $listener = 'App\\Listener\\Double';

        $store->markProcessed($eventId, $listener);
        // Second call MUST be silent — the port contract is "safe to
        // call twice". The race between two workers reaching markProcessed
        // with the same key would otherwise crash the dispatcher.
        $store->markProcessed($eventId, $listener);

        $count = Database::connect()->table('processed_events')
            ->where('event_id', $eventId)
            ->where('listener_class', $listener)
            ->countAllResults();
        $this->assertSame(1, $count);
    }

    public function test_different_listeners_for_same_event_are_recorded_independently(): void
    {
        // Both keys are required — see ProcessedEventStoreInterface
        // docblock. Each listener has its own at-most-once channel.
        $store = new DatabaseProcessedEventStore();
        $eventId = '019000a0-0000-7000-8000-000000000004';

        $store->markProcessed($eventId, 'App\\Listener\\A');

        $this->assertTrue($store->isProcessed($eventId, 'App\\Listener\\A'));
        $this->assertFalse(
            $store->isProcessed($eventId, 'App\\Listener\\B'),
            'Listener B has not been marked yet — its channel must still be open.'
        );
    }

    public function test_same_listener_for_different_events_records_independently(): void
    {
        $store = new DatabaseProcessedEventStore();
        $listener = 'App\\Listener\\Bus';

        $store->markProcessed('019000a0-0000-7000-8000-000000000005', $listener);

        $this->assertTrue($store->isProcessed('019000a0-0000-7000-8000-000000000005', $listener));
        $this->assertFalse(
            $store->isProcessed('019000a0-0000-7000-8000-000000000006', $listener),
            'A second event id must be a separate at-most-once channel.'
        );
    }
}
