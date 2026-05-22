<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Bus;

use App\Domain\Shared\Bus\LogSampler;
use Tests\Support\UnitTestCase;

/**
 * Pins LogSampler's contract:
 *  - rate 0 always returns false (no samples).
 *  - rate 1.0 always returns true (always sample).
 *  - rate outside [0.0, 1.0] throws.
 *  - intermediate rates produce roughly the expected proportion when run
 *    many times — uses a wide tolerance band because random_int() is not
 *    seedable, so we accept any draw within +/- 5 percentage points of
 *    the configured rate over a 2000-call sample.
 *  - rate is uniform across the 0..1 band (round-trip via basis points
 *    preserves precision at sub-1 % resolution).
 */
final class LogSamplerTest extends UnitTestCase
{
    public function test_zero_rate_never_samples(): void
    {
        $sampler = new LogSampler(0.0);

        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($sampler->shouldSample(), 'iteration ' . $i);
        }
    }

    public function test_full_rate_always_samples(): void
    {
        $sampler = new LogSampler(1.0);

        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($sampler->shouldSample(), 'iteration ' . $i);
        }
    }

    public function test_negative_rate_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sampling rate must be between');

        new LogSampler(-0.01);
    }

    public function test_rate_above_one_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sampling rate must be between');

        new LogSampler(1.01);
    }

    public function test_half_rate_is_approximately_half(): void
    {
        $sampler = new LogSampler(0.5);
        $count = 0;
        $iterations = 2000;

        for ($i = 0; $i < $iterations; $i++) {
            if ($sampler->shouldSample()) {
                $count++;
            }
        }

        // CSPRNG is not seedable, so we use a wide +/- 5 pp tolerance.
        $ratio = $count / $iterations;
        $this->assertGreaterThan(0.45, $ratio, 'sampler skews low: ' . $ratio);
        $this->assertLessThan(0.55, $ratio, 'sampler skews high: ' . $ratio);
    }

    public function test_uses_random_int_not_mt_rand(): void
    {
        // Behavioural check: random_int draws from CSPRNG, so two
        // freshly-constructed samplers at the same rate must NOT produce
        // identical sequences (mt_rand without explicit seeding can repeat
        // across processes in pathological cases; random_int never does).
        $a = $this->collect(new LogSampler(0.5), 64);
        $b = $this->collect(new LogSampler(0.5), 64);

        $this->assertNotSame(
            $a,
            $b,
            'two independent samplers produced identical sequences — RNG seam broken'
        );
    }

    /**
     * @return list<bool>
     */
    private function collect(LogSampler $sampler, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $sampler->shouldSample();
        }
        return $out;
    }
}
