<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Minimal clock seam used by the abstract handler bases.
 *
 * Why a custom port instead of PSR-20:
 *  - psr/clock is not pulled in by this project today (only psr/log /
 *    psr/container ship via Monolog). Introducing it just for the
 *    `now()` API is a heavier dependency than necessary.
 *  - The abstract bases only need a monotonic, sub-millisecond `now`
 *    source for duration measurement (start vs end), not a DateTime.
 *    Returning a float (seconds since an arbitrary epoch) keeps the
 *    arithmetic trivial.
 *
 * The default production implementation is {@see SystemClock}, which
 * delegates to `hrtime(true)` for monotonicity (closes 03/F11 — the
 * Cookie handlers used a mix of `microtime(true)` and `hrtime(true)`).
 *
 * Tests can substitute a fake clock returning a controlled sequence
 * to assert deterministic duration values.
 *
 * @package App\Domain\Shared\Bus
 */
interface ClockInterface
{
    /**
     * Monotonic timestamp in seconds (with fractional ms precision).
     *
     * The absolute value is meaningless on its own; only differences
     * between two calls are. Implementations MUST be monotonically
     * non-decreasing within a single process.
     *
     * @return float Seconds since an implementation-defined epoch.
     */
    public function now(): float;
}
