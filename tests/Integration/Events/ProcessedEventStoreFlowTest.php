<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Events\DatabaseProcessedEventStore;
use Tests\Support\IntegrationTestCase;

/**
 * End-to-end exercise of the round-3 E12.5 at-most-once flow.
 *
 * Where the dispatcher unit test uses an in-memory store and the
 * adapter test exercises the SQL directly, this integration glues both
 * together: it binds the real DatabaseProcessedEventStore onto a real
 * EventDispatcher, fires an event twice, and asserts the listener only
 * ran on the first dispatch — exactly the protection slice 05/F5 was
 * raised for.
 */
final class ProcessedEventStoreFlowTest extends IntegrationTestCase
{
    public function test_replaying_the_same_event_invokes_listener_once(): void
    {
        $store = new DatabaseProcessedEventStore();
        $dispatcher = new EventDispatcher();
        $dispatcher->setProcessedEventStore($store);

        $calls = 0;
        $dispatcher->subscribe(
            FlowTestEvent::class,
            static function (FlowTestEvent $event) use (&$calls): void {
                // The flow test doesn't inspect the event body — just
                // counts invocations.
                unset($event);
                $calls++;
            }
        );

        $event = new FlowTestEvent('019000a0-0000-7000-8000-cccccccccccc');

        // First dispatch — listener must run AND the pair must be marked.
        $dispatcher->dispatch($event);
        $this->assertSame(1, $calls);
        $this->seeInDatabase('processed_events', [
            'event_id' => $event->eventId,
            'listener_class' => 'Closure',
        ]);

        // Replay — listener must NOT run again. This is the at-most-once
        // guarantee the epic was created for.
        $dispatcher->dispatch($event);
        $this->assertSame(1, $calls, 'Replay must be a no-op when the pair is already marked.');
    }

    public function test_replaying_after_failure_invokes_listener_until_success(): void
    {
        // The listener throws the first time, succeeds the second time.
        // The dispatcher must NOT mark on failure, so the retry must
        // invoke the listener again — and only THEN mark.
        $store = new DatabaseProcessedEventStore();
        $dispatcher = new EventDispatcher();
        $dispatcher->setProcessedEventStore($store);

        // Wrap the counter in an object reference so PHPStan can't
        // narrow each `assertSame` against the closure's most recent
        // increment — by-ref scalars confuse the analyser into thinking
        // the post-dispatch value is statically known.
        $counter = new FlowCounter();
        $dispatcher->subscribe(
            FlowTestEvent::class,
            static function () use ($counter): void {
                $counter->increment();
                if ($counter->value() === 1) {
                    throw new \RuntimeException('transient');
                }
            }
        );

        $event = new FlowTestEvent('019000a0-0000-7000-8000-dddddddddddd');

        // Attempt 1 — listener throws, dispatcher logs (default mode is
        // log-and-continue), pair MUST stay unmarked.
        $dispatcher->dispatch($event);
        $this->assertSame(1, $counter->value());
        $this->dontSeeInDatabase('processed_events', [
            'event_id' => $event->eventId,
            'listener_class' => 'Closure',
        ]);

        // Attempt 2 — listener succeeds, pair is finally marked.
        $dispatcher->dispatch($event);
        $this->assertSame(2, $counter->value());
        $this->seeInDatabase('processed_events', [
            'event_id' => $event->eventId,
            'listener_class' => 'Closure',
        ]);

        // Attempt 3 — listener stays at 2, dedup is in force.
        $dispatcher->dispatch($event);
        $this->assertSame(2, $counter->value(), 'Third attempt must be deduped.');
    }
}
