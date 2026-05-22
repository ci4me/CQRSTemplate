<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Aggregate;

use App\Domain\Shared\Aggregate\AggregateHydrator;
use ReflectionClass;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for the {@see AggregateHydrator} permission token.
 *
 * The contract verified here is:
 *  - `AggregateHydrator::key()` returns a fresh instance.
 *  - The constructor is `private` so the only path to mint a key is via
 *    the public static factory (a future PHPStan rule will tighten WHO
 *    may call that factory — see epic E05.5).
 *  - The class is `final` so domains cannot subclass to grant
 *    themselves a privileged hydrator.
 */
final class AggregateHydratorTest extends UnitTestCase
{
    public function test_key_returns_aggregate_hydrator_instance(): void
    {
        $key = AggregateHydrator::key();

        $this->assertInstanceOf(AggregateHydrator::class, $key);
    }

    public function test_key_returns_a_fresh_instance_each_call(): void
    {
        $first = AggregateHydrator::key();
        $second = AggregateHydrator::key();

        $this->assertNotSame(
            $first,
            $second,
            'key() should mint a fresh token so a leaked reference cannot be replayed'
        );
    }

    public function test_constructor_is_private(): void
    {
        $reflection = new ReflectionClass(AggregateHydrator::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'AggregateHydrator must declare an explicit constructor');
        $this->assertTrue(
            $constructor->isPrivate(),
            'AggregateHydrator::__construct must be private so callers cannot forge a key with `new`'
        );
    }

    public function test_class_is_final(): void
    {
        $reflection = new ReflectionClass(AggregateHydrator::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'AggregateHydrator must be final so domains cannot subclass to bypass the key contract'
        );
    }
}
