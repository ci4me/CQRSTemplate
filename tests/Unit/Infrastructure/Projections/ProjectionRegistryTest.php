<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Projections;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Projections\ProjectionInterface;
use App\Infrastructure\Projections\ProjectionRegistry;
use RuntimeException;
use Tests\Support\UnitTestCase;

final class ProjectionRegistryTest extends UnitTestCase
{
    public function test_register_indexes_projection_by_name(): void
    {
        $dispatcher = new EventDispatcher();
        $registry = new ProjectionRegistry($dispatcher);
        $projection = $this->makeProjection('orders', []);

        $registry->register($projection);

        $this->assertSame(['orders' => $projection], $registry->all());
        $this->assertSame($projection, $registry->get('orders'));
    }

    public function test_register_subscribes_apply_for_each_event_class(): void
    {
        $dispatcher = new EventDispatcher();
        $registry = new ProjectionRegistry($dispatcher);
        $received = [];
        $projection = $this->makeProjection(
            'audit',
            [DummyEventA::class, DummyEventB::class],
            static function (object $event) use (&$received): void {
                $received[] = $event::class;
            },
        );

        $registry->register($projection);
        $dispatcher->dispatch(new DummyEventA());
        $dispatcher->dispatch(new DummyEventB());

        $this->assertSame([DummyEventA::class, DummyEventB::class], $received);
    }

    public function test_get_throws_when_projection_is_unknown(): void
    {
        $registry = new ProjectionRegistry(new EventDispatcher());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Projection "missing" is not registered.');

        $registry->get('missing');
    }

    /**
     * @param list<class-string> $subscribesTo
     */
    private function makeProjection(string $name, array $subscribesTo, ?callable $onApply = null): ProjectionInterface
    {
        return new class ($name, $subscribesTo, $onApply) implements ProjectionInterface {
            /**
             * @param list<class-string> $subscribesTo
             */
            public function __construct(
                private readonly string $name,
                private readonly array $subscribesTo,
                private $onApply,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function subscribesTo(): array
            {
                return $this->subscribesTo;
            }

            public function apply(object $event): void
            {
                if ($this->onApply !== null) {
                    ($this->onApply)($event);
                }
            }

            public function truncate(): void
            {
            }

            public function rebuildFromSource(?callable $progressCallback = null): void
            {
            }
        };
    }
}

final class DummyEventA
{
}

final class DummyEventB
{
}
