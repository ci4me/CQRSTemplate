<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus;

use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\CommandMiddlewareInterface;
use Tests\Support\UnitTestCase;

/**
 * Pins the CommandBus contract (parallel to QueryBusTest):
 *  - one command class -> one handler
 *  - second registration for the same class fails loudly
 *  - handler must expose a handle() method, validated at register time
 *  - dispatch() routes to the handler and returns its result
 *  - dispatch() with no registered handler throws DomainException
 *  - hasHandler() reports correctly
 *  - setMiddleware() replaces the entire pipeline (test-only escape hatch)
 *
 * The dispatch-through-middleware pipeline ordering is covered separately by
 * {@see CommandBusMiddlewareTest}; this file focuses on the bus's own state.
 */
final class CommandBusTest extends UnitTestCase
{
    public function test_registered_handler_routes_via_dispatch(): void
    {
        $bus = new CommandBus();
        $handler = new class implements CommandHandlerInterface {
            public function handle(object $command): string
            {
                /** @phpstan-ignore-next-line dynamic property — SampleCommand is the bus-narrowed type. */
                return 'handled-' . $command->payload;
            }
        };

        $bus->register(SampleCommand::class, $handler);
        $result = $bus->dispatch(new SampleCommand('x'));

        $this->assertSame('handled-x', $result);
    }

    public function test_duplicate_registration_throws(): void
    {
        $bus = new CommandBus();
        $bus->register(SampleCommand::class, $this->stubHandler());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        $bus->register(SampleCommand::class, $this->stubHandler());
    }

    public function test_handler_without_interface_is_rejected_at_register(): void
    {
        $bus = new CommandBus();

        // Bus::register() typehints CommandHandlerInterface, so passing a
        // bare stdClass triggers a PHP TypeError BEFORE the bus's own
        // sanity check runs — that's the structural guarantee E05 introduces
        // (closes 03/F5: typos in handler files fail at boot, not first
        // dispatch). The TypeError happens during argument resolution.
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line argument.type — testing the runtime guard. */
        $bus->register(SampleCommand::class, new \stdClass());
    }

    public function test_dispatch_without_registered_handler_throws_domain_exception(): void
    {
        $bus = new CommandBus();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No handler registered');

        $bus->dispatch(new SampleCommand('orphan'));
    }

    public function test_has_handler_reports_registration_state(): void
    {
        $bus = new CommandBus();
        $this->assertFalse($bus->hasHandler(SampleCommand::class));

        $bus->register(SampleCommand::class, $this->stubHandler());
        $this->assertTrue($bus->hasHandler(SampleCommand::class));
    }

    public function test_set_middleware_replaces_pipeline(): void
    {
        $bus = new CommandBus();

        $bus->pushMiddleware(new class implements CommandMiddlewareInterface {
            public function handle(object $command, callable $next): mixed
            {
                return 'old-pipeline';
            }
        });

        $bus->setMiddleware([
            new class implements CommandMiddlewareInterface {
                public function handle(object $command, callable $next): mixed
                {
                    return 'new-pipeline';
                }
            },
        ]);

        $bus->register(SampleCommand::class, $this->stubHandler());

        $this->assertSame('new-pipeline', $bus->dispatch(new SampleCommand('x')));
    }

    public function test_empty_middleware_pipeline_calls_handler_directly(): void
    {
        $bus = new CommandBus();
        $bus->register(SampleCommand::class, new class implements CommandHandlerInterface {
            public function handle(object $command): string
            {
                /** @phpstan-ignore-next-line dynamic property. */
                return 'direct:' . $command->payload;
            }
        });

        // No middleware pushed -> the array_reduce in dispatch() should fall
        // through to the bare $core invocation. Regression guard for that
        // edge case.
        $this->assertSame('direct:y', $bus->dispatch(new SampleCommand('y')));
    }

    private function stubHandler(): CommandHandlerInterface
    {
        return new class implements CommandHandlerInterface {
            public function handle(object $command): mixed
            {
                return null;
            }
        };
    }
}

/**
 * Inline test fixture — pure data carrier, mirrors a real command DTO.
 */
final readonly class SampleCommand
{
    public function __construct(public string $payload)
    {
    }
}
