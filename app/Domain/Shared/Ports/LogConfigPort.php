<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ports;

/**
 * Domain port for read-only access to logging configuration.
 *
 * Domain handlers and repositories use this port to decide whether to log a
 * query (slow / errors / sampling / all) without importing the framework
 * `Config\Logging` class directly. The concrete adapter lives in
 * Infrastructure and adapts CodeIgniter's config to this contract.
 *
 * Methods are intentionally narrow — only the values actually consumed by
 * domain code are exposed. New domains that need more knobs should extend
 * this port rather than reaching back into `Config\Logging`.
 *
 * @package App\Domain\Shared\Ports
 */
interface LogConfigPort
{
    /**
     * Slow-query threshold in milliseconds.
     *
     * Queries whose measured duration exceeds this value are unconditionally
     * logged regardless of the configured logging level.
     *
     * @return int Threshold in ms (e.g. 100).
     */
    public function slowQueryThresholdMs(): int;

    /**
     * Random sampling rate, as a float between 0.0 and 1.0.
     *
     * Used by domain code when {@see queryLoggingLevel()} is `sampling` —
     * e.g. 0.01 means log roughly 1% of queries at random.
     *
     * @return float Sampling rate (0.0–1.0).
     */
    public function samplingRate(): float;

    /**
     * Query logging level: 'all' | 'errors' | 'slow' | 'sampling'.
     *
     * Domain code matches on this string to decide whether a given query
     * should be logged at the configured verbosity.
     *
     * @return string The active logging level token.
     */
    public function queryLoggingLevel(): string;
}
