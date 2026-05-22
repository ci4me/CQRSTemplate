<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus;

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
