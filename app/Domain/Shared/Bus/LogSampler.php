<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

/**
 * Single source of truth for log-sampling decisions.
 *
 * Replaces the `mt_rand() / mt_getrandmax() < $rate` pattern that the
 * three Cookie query handlers each carried a private copy of (closes
 * 04/F12, 14/F20, 17/F2 partial). Two things change:
 *
 *  1. Cryptographically-seeded RNG.
 *     `mt_rand()` is the Mersenne Twister and biases the low bits at
 *     extreme sampling rates; `random_int()` uses the platform CSPRNG
 *     and is uniform across the full integer range. PHP 8.4's
 *     `Random\Randomizer` is a future replacement (tracked under E16).
 *
 *  2. One copy of the policy.
 *     The 0.0–1.0 float rate is converted once to an integer in
 *     [0, 10_000] (10_000 = 100 %). Comparison stays in integer space
 *     to avoid float-equality surprises around the boundary.
 *
 * Usage:
 *
 *   $sampler = new LogSampler(0.01); // 1 %
 *   if ($sampler->shouldSample()) { $logger->info(...); }
 *
 * Constructor validates the input is in [0.0, 1.0] so misconfiguration
 * fails loud instead of silently disabling all sampling.
 *
 * @package App\Domain\Shared\Bus
 */
final readonly class LogSampler
{
    /**
     * Sampling rate scaled to integer basis points (out of 10_000).
     *
     * Storing the rate as an integer in [0, 10_000] avoids float
     * comparisons at the hot path while preserving 4-decimal precision
     * for sub-1% sampling (e.g. 0.0001 = 0.01 %).
     */
    private int $rateBasisPoints;

    /**
     * @param float $rate Sampling rate as a float in [0.0, 1.0].
     *                    0.0 disables sampling entirely; 1.0 always samples.
     * @throws \InvalidArgumentException If $rate is outside [0.0, 1.0].
     */
    public function __construct(float $rate)
    {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new \InvalidArgumentException(
                sprintf('Sampling rate must be between 0.0 and 1.0, got %f', $rate)
            );
        }

        $this->rateBasisPoints = (int) round($rate * 10_000);
    }

    /**
     * Roll the dice: should this call be logged?
     *
     * Uses `random_int(1, 10_000)` (CSPRNG-backed, uniform) and compares
     * to the rate in basis points. A rate of 0 always returns false;
     * a rate of 1.0 always returns true.
     *
     * @return bool True when the current invocation falls into the
     *              sampled fraction.
     */
    public function shouldSample(): bool
    {
        if ($this->rateBasisPoints <= 0) {
            return false;
        }

        if ($this->rateBasisPoints >= 10_000) {
            return true;
        }

        return random_int(1, 10_000) <= $this->rateBasisPoints;
    }
}
