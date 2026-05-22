<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus;

use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\ProcessedEventStoreInterface;
use App\Infrastructure\Bus\EventDispatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class EventDispatcherTest extends UnitTestCase
{
    public function test_dispatches_to_every_subscribed_listener_in_order(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new \stdClass();

        $calls = [];
        $dispatcher->subscribe(\stdClass::class, static function ($e) use (&$calls): void {
            $calls[] = 'first';
        });
        $dispatcher->subscribe(\stdClass::class, static function ($e) use (&$calls): void {
            $calls[] = 'second';
        });

        $dispatcher->dispatch($event);

        $this->assertSame(['first', 'second'], $calls);
    }

    public function test_does_nothing_when_no_listeners_registered(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->dispatch(new \stdClass());

        $this->assertFalse($dispatcher->hasListeners(\stdClass::class));
        $this->assertSame(0, $dispatcher->getListenerCount(\stdClass::class));
    }

    public function test_listener_exception_is_logged_and_others_still_run(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Event listener failed'),
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['event_class'] ?? null) === \stdClass::class
                        && isset($ctx['listener'])
                        && isset($ctx['exception'])
                        && isset($ctx['correlation_id']);
                })
            );

        $dispatcher = new EventDispatcher($logger);

        $secondRan = false;
        $dispatcher->subscribe(\stdClass::class, static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $dispatcher->subscribe(\stdClass::class, static function () use (&$secondRan): void {
            $secondRan = true;
        });

        $dispatcher->dispatch(new \stdClass());

        $this->assertTrue($secondRan, 'second listener should run despite the first throwing');
    }

    public function test_listener_count_reflects_registration(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(\stdClass::class, static fn() => null);
        $dispatcher->subscribe(\stdClass::class, static fn() => null);

        $this->assertSame(2, $dispatcher->getListenerCount(\stdClass::class));
        $this->assertTrue($dispatcher->hasListeners(\stdClass::class));
    }

    public function test_set_rethrow_on_listener_failure_propagates_and_stops_fanout(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dispatcher = new EventDispatcher($logger);

        $previous = $dispatcher->setRethrowOnListenerFailure(true);
        $this->assertFalse($previous, 'default mode should be log-and-continue');

        $secondRan = false;
        $dispatcher->subscribe(\stdClass::class, static function (): void {
            throw new \RuntimeException('rethrow boom');
        });
        $dispatcher->subscribe(\stdClass::class, static function () use (&$secondRan): void {
            $secondRan = true;
        });

        try {
            $dispatcher->dispatch(new \stdClass());
            $this->fail('exception should have propagated when rethrow mode is on');
        } catch (\RuntimeException $e) {
            $this->assertSame('rethrow boom', $e->getMessage());
        }

        $this->assertFalse($secondRan, 'fanout must stop on first failure when rethrow mode is on');
    }

    public function test_set_rethrow_returns_previous_value_for_restore_in_finally(): void
    {
        $dispatcher = new EventDispatcher();

        $first = $dispatcher->setRethrowOnListenerFailure(true);
        $this->assertFalse($first);

        $second = $dispatcher->setRethrowOnListenerFailure(false);
        $this->assertTrue($second, 'must return the *previous* state so callers can restore it');
    }

    public function test_listener_describer_records_invokable_class_name(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['listener'] ?? null) === FailingInvokable::class;
                })
            );

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->subscribe(\stdClass::class, new FailingInvokable());
        $dispatcher->dispatch(new \stdClass());
    }

    public function test_listener_describer_records_array_callable_as_class_method(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['listener'] ?? null) === FailingListenerObject::class . '::onEvent';
                })
            );

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->subscribe(\stdClass::class, [new FailingListenerObject(), 'onEvent']);
        $dispatcher->dispatch(new \stdClass());
    }

    public function test_warn_on_no_listeners_emits_debug_log_when_enabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'Event dispatched with no listeners',
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['event_class'] ?? null) === \stdClass::class
                        && ($ctx['component'] ?? null) === 'EventDispatcher'
                        && array_key_exists('correlation_id', $ctx);
                })
            );

        $dispatcher = new EventDispatcher($logger);
        $previous = $dispatcher->setWarnOnNoListeners(true);
        $this->assertFalse($previous, 'default mode must be silent on zero-listener dispatches');

        $dispatcher->dispatch(new \stdClass());
    }

    public function test_warn_on_no_listeners_is_silent_by_default(): void
    {
        // Production must not pay for the diagnostic by default; the
        // logger MUST NOT see a debug call when the hook is off.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->dispatch(new \stdClass());
    }

    public function test_set_warn_on_no_listeners_returns_previous_value_for_finally_restore(): void
    {
        $dispatcher = new EventDispatcher();

        $first = $dispatcher->setWarnOnNoListeners(true);
        $this->assertFalse($first);

        $second = $dispatcher->setWarnOnNoListeners(false);
        $this->assertTrue($second, 'must return the *previous* state so callers can restore it');
    }

    public function test_warn_on_no_listeners_does_not_fire_when_listeners_exist(): void
    {
        // Sanity: enabling the hook must NOT log on every dispatch — only
        // on the zero-listener branch.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->setWarnOnNoListeners(true);
        $dispatcher->subscribe(\stdClass::class, static fn () => null);

        $dispatcher->dispatch(new \stdClass());
    }

    public function test_listener_describer_falls_back_to_closure_label(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['listener'] ?? null) === 'Closure';
                })
            );

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->subscribe(\stdClass::class, static function (): void {
            throw new \RuntimeException('closure boom');
        });
        $dispatcher->dispatch(new \stdClass());
    }

    public function test_processed_event_store_skips_already_processed_listeners(): void
    {
        // Pre-populate the store as if a previous worker had completed
        // listener A but died before ACKing. On replay the dispatcher
        // MUST skip A (its describe-string is recorded) and still
        // invoke any sibling listener whose pair is unrecorded.
        // The two listeners are DIFFERENT classes so describeListener()
        // returns distinct labels — that's the actual key the dispatcher
        // uses, not an instance tag.
        $store = new InMemoryProcessedEventStore();
        $event = new FakeDomainEvent('019000a0-0000-7000-8000-aaaaaaaaaaaa');

        $store->markProcessed($event->eventId, RecordingListenerA::class . '::onEvent');

        $a = new RecordingListenerA();
        $b = new RecordingListenerB();

        $dispatcher = new EventDispatcher();
        $dispatcher->setProcessedEventStore($store);
        $dispatcher->subscribe(FakeDomainEvent::class, [$a, 'onEvent']);
        $dispatcher->subscribe(FakeDomainEvent::class, [$b, 'onEvent']);

        $dispatcher->dispatch($event);

        $this->assertSame(0, $a->called, 'Listener A was already processed — must be skipped.');
        $this->assertSame(1, $b->called, 'Listener B was not yet processed — must run.');
        $this->assertTrue(
            $store->isProcessed($event->eventId, RecordingListenerB::class . '::onEvent'),
            'A successful invocation MUST be recorded.'
        );
    }

    public function test_listener_failure_leaves_event_unmarked(): void
    {
        // Failure path of the at-most-once contract: a thrown listener
        // must NOT be marked, so the retry succeeds on the next attempt.
        $store = new InMemoryProcessedEventStore();
        $event = new FakeDomainEvent('019000a0-0000-7000-8000-bbbbbbbbbbbb');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->setProcessedEventStore($store);
        $dispatcher->subscribe(FakeDomainEvent::class, static function (): void {
            throw new \RuntimeException('listener boom');
        });

        $dispatcher->dispatch($event);

        $this->assertFalse(
            $store->isProcessed($event->eventId, 'Closure'),
            'A thrown listener must NOT be marked — the retry must run again.'
        );
    }

    public function test_processed_event_store_setter_returns_previous_value(): void
    {
        // Same finally-block restore pattern as setRethrowOnListenerFailure.
        $dispatcher = new EventDispatcher();
        $store = new InMemoryProcessedEventStore();

        $this->assertNull($dispatcher->setProcessedEventStore($store));
        $this->assertSame($store, $dispatcher->setProcessedEventStore(null));
        $this->assertNull($dispatcher->setProcessedEventStore(null));
    }

    public function test_processed_event_store_is_bypassed_for_events_without_event_id(): void
    {
        // Events not extending AbstractDomainEvent legitimately exist
        // (e.g. legacy events pre-E04, framework-internal signals). The
        // dispatcher must silently skip the dedup bracket so those flows
        // still work — the alternative would crash on `$event->eventId`.
        $store = new InMemoryProcessedEventStore();
        $listener = new RecordingListenerA();

        $dispatcher = new EventDispatcher();
        $dispatcher->setProcessedEventStore($store);
        $dispatcher->subscribe(\stdClass::class, [$listener, 'onEvent']);

        $dispatcher->dispatch(new \stdClass());

        $this->assertSame(1, $listener->called);
        $this->assertSame([], $store->dump(), 'No envelope id means no row should be recorded.');
    }
}

/**
 * Inline fixtures for the listener-describer tests. They live in this file
 * (not in tests/Support) because they exist only to exercise the
 * `describeListener()` private method's branches.
 */
final class FailingInvokable
{
    public function __invoke(object $event): void
    {
        throw new \RuntimeException('invokable boom');
    }
}

final class FailingListenerObject
{
    public function onEvent(object $event): void
    {
        throw new \RuntimeException('method boom');
    }
}

/**
 * Concrete AbstractDomainEvent subclass for the E12.5 dispatcher tests.
 * Lives in this file because it exists only to prove the dispatcher's
 * isProcessed/markProcessed bracket honours the `eventId` envelope.
 */
final readonly class FakeDomainEvent extends AbstractDomainEvent
{
    public function __construct(string $eventId)
    {
        parent::__construct(
            eventId: $eventId,
            occurredAt: new \DateTimeImmutable('2026-05-22T00:00:00+00:00'),
            actorId: null,
            aggregateType: 'Fake',
            aggregateId: 'fake-1',
        );
    }
}

/**
 * Recording listeners A + B — two distinct classes so describeListener()
 * yields distinct labels for the at-most-once tests. Using a single class
 * with an instance tag would not work: the dispatcher's dedup key is
 * `(eventId, describeListener(callable))`, and the describe-string for
 * `[obj, 'onEvent']` is the class FQCN, not the instance.
 */
final class RecordingListenerA
{
    public int $called = 0;

    public function onEvent(object $event): void
    {
        $this->called++;
    }
}

final class RecordingListenerB
{
    public int $called = 0;

    public function onEvent(object $event): void
    {
        $this->called++;
    }
}

/**
 * In-memory ProcessedEventStore for the dispatcher unit tests. The
 * adapter-level INSERT IGNORE / SELECT path is exercised by the
 * integration suite (see Tests\Integration\Events\DatabaseProcessedEventStoreTest);
 * this fake exists so the dispatcher tests stay in the unit tier.
 */
final class InMemoryProcessedEventStore implements ProcessedEventStoreInterface
{
    /** @var array<string, true> */
    private array $marks = [];

    public function isProcessed(string $eventId, string $listenerClass): bool
    {
        return isset($this->marks[$this->key($eventId, $listenerClass)]);
    }

    public function markProcessed(string $eventId, string $listenerClass): void
    {
        $this->marks[$this->key($eventId, $listenerClass)] = true;
    }

    /**
     * @return array<string, true>
     */
    public function dump(): array
    {
        return $this->marks;
    }

    private function key(string $eventId, string $listenerClass): string
    {
        return $eventId . '|' . $listenerClass;
    }
}
