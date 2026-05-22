<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus;

use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\QueryBus;
use Tests\Support\UnitTestCase;

/**
 * Pins the QueryBus contract:
 *  - one query class -> one handler
 *  - second registration for the same class fails loudly
 *  - handler must expose a handle() method, validated at register time
 *  - ask() routes to the handler and returns its result
 *  - ask() with no registered handler throws DomainException
 *  - hasHandler() reports correctly
 */
final class QueryBusTest extends UnitTestCase
{
    public function test_registered_handler_routes_via_ask(): void
    {
        $bus = new QueryBus();
        $handler = new class implements QueryHandlerInterface {
            public function handle(object $query): string
            {
                /** @phpstan-ignore-next-line dynamic property */
                return 'hello-' . $query->name;
            }
        };

        $bus->register(SampleQuery::class, $handler);
        $result = $bus->ask(new SampleQuery('world'));

        $this->assertSame('hello-world', $result);
    }

    public function test_duplicate_registration_throws(): void
    {
        $bus = new QueryBus();
        $bus->register(SampleQuery::class, $this->stubHandler());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        $bus->register(SampleQuery::class, $this->stubHandler());
    }

    public function test_handler_without_interface_is_rejected_at_register(): void
    {
        $bus = new QueryBus();

        // Bus::register() typehints QueryHandlerInterface; passing a bare
        // stdClass triggers a PHP TypeError at argument resolution time
        // — the structural register-time guarantee E05 introduces
        // (closes 04/F3).
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line argument.type — testing the runtime guard. */
        $bus->register(SampleQuery::class, new \stdClass());
    }

    public function test_ask_without_registered_handler_throws_domain_exception(): void
    {
        $bus = new QueryBus();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No handler registered');

        $bus->ask(new SampleQuery('x'));
    }

    public function test_has_handler_reports_registration_state(): void
    {
        $bus = new QueryBus();
        $this->assertFalse($bus->hasHandler(SampleQuery::class));

        $bus->register(SampleQuery::class, $this->stubHandler());
        $this->assertTrue($bus->hasHandler(SampleQuery::class));
    }

    private function stubHandler(): QueryHandlerInterface
    {
        return new class implements QueryHandlerInterface {
            public function handle(object $query): mixed
            {
                return null;
            }
        };
    }
}

/**
 * Inline test fixture — pure data carrier, mirrors a real query DTO.
 */
final readonly class SampleQuery
{
    public function __construct(public string $name)
    {
    }
}
