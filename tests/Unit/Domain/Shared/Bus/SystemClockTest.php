<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Bus;

use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\SystemClock;
use Tests\Support\UnitTestCase;

/**
 * Pins SystemClock's contract:
 *  - implements ClockInterface (allows polymorphic injection into
 *    AbstractCommandHandler / AbstractQueryHandler).
 *  - returns a positive float (seconds since hrtime(true) epoch).
 *  - is monotonically non-decreasing within the same process.
 */
final class SystemClockTest extends UnitTestCase
{
    public function test_implements_clock_interface(): void
    {
        $this->assertInstanceOf(ClockInterface::class, new SystemClock());
    }

    public function test_returns_positive_float(): void
    {
        $clock = new SystemClock();
        $now = $clock->now();

        $this->assertIsFloat($now);
        $this->assertGreaterThan(0.0, $now);
    }

    public function test_is_monotonic_within_process(): void
    {
        $clock = new SystemClock();

        $previous = $clock->now();
        for ($i = 0; $i < 10; $i++) {
            $current = $clock->now();
            $this->assertGreaterThanOrEqual(
                $previous,
                $current,
                'Clock went backwards at iteration ' . $i
            );
            $previous = $current;
        }
    }
}
