<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Default {@see ClockInterface} implementation backed by `hrtime(true)`.
 *
 * `hrtime(true)` returns nanoseconds from the system's monotonic timer,
 * which is unaffected by wall-clock jumps (NTP adjustments, daylight
 * saving transitions). Dividing by 1e9 converts to seconds so the
 * return shape matches the typical `microtime(true)` idiom — callers
 * compute `($clock->now() - $start) * 1000` for ms duration.
 *
 * Stateless and side-effect-free: safe to share as a singleton.
 *
 * @package App\Domain\Shared\Bus
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * Return the current monotonic time in seconds.
     *
     * Nanoseconds from `hrtime(true)` divided by 1e9. Precision is
     * preserved well within the float64 mantissa for any realistic
     * process lifetime.
     *
     * @return float Monotonic seconds.
     */
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
