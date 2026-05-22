<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus;

use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\CommandMiddlewareInterface;
use Tests\Support\UnitTestCase;

final class CommandBusMiddlewareTest extends UnitTestCase
{
    public function test_middlewares_wrap_handler_in_registration_order(): void
    {
        $bus = new CommandBus();

        $log = [];
        $bus->pushMiddleware(new class ($log) implements CommandMiddlewareInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }
            public function handle(object $command, callable $next): mixed
            {
                $this->log[] = 'outer:before';
                $r = $next($command);
                $this->log[] = 'outer:after';
                return $r;
            }
        });
        $bus->pushMiddleware(new class ($log) implements CommandMiddlewareInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }
            public function handle(object $command, callable $next): mixed
            {
                $this->log[] = 'inner:before';
                $r = $next($command);
                $this->log[] = 'inner:after';
                return $r;
            }
        });

        $bus->register(\stdClass::class, new class ($log) implements CommandHandlerInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }
            public function handle(object $command): string
            {
                unset($command);
                $this->log[] = 'handler';
                return 'ok';
            }
        });

        $result = $bus->dispatch(new \stdClass());

        $this->assertSame('ok', $result);
        $this->assertSame(
            ['outer:before', 'inner:before', 'handler', 'inner:after', 'outer:after'],
            $log
        );
    }

    public function test_middleware_can_short_circuit_by_not_calling_next(): void
    {
        $bus = new CommandBus();

        $bus->pushMiddleware(new class implements CommandMiddlewareInterface {
            public function handle(object $command, callable $next): mixed
            {
                return 'short-circuit';
            }
        });

        $bus->register(\stdClass::class, new class implements CommandHandlerInterface {
            public function handle(object $command): string
            {
                unset($command);
                return 'should-not-run';
            }
        });

        $this->assertSame('short-circuit', $bus->dispatch(new \stdClass()));
    }

    public function test_middleware_exception_propagates(): void
    {
        $bus = new CommandBus();
        $bus->pushMiddleware(new class implements CommandMiddlewareInterface {
            public function handle(object $command, callable $next): mixed
            {
                throw new \RuntimeException('mw boom');
            }
        });
        $bus->register(\stdClass::class, new class implements CommandHandlerInterface {
            public function handle(object $command): string
            {
                unset($command);
                return 'never';
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mw boom');

        $bus->dispatch(new \stdClass());
    }
}
