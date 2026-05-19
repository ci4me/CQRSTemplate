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
}
