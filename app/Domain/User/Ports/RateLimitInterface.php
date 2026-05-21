<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

use App\Domain\Shared\ValueObjects\RateLimitResult;

/**
 * Rate Limit Interface.
 *
 * Port for rate limiting functionality using token bucket algorithm.
 */
interface RateLimitInterface
{
    /**
     * Check if request is within rate limit.
     *
     * @param string $identifier    Unique identifier (IP address, user ID, etc.)
     * @param int    $maxAttempts   Maximum number of attempts allowed
     * @param int    $windowSeconds Time window in seconds
     * @return RateLimitResult Result containing allowed status and metadata
     */
    public function checkLimit(string $identifier, int $maxAttempts, int $windowSeconds): RateLimitResult;

    /**
     * Reset rate limit for a specific identifier.
     *
     * @param string $identifier Unique identifier to reset
     */
    public function reset(string $identifier): void;
}
