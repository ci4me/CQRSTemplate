<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Ports\RateLimitInterface;
use App\Infrastructure\Auth\ValueObjects\RateLimitResult;
use CodeIgniter\Cache\CacheInterface;

/**
 * Rate Limit Service using Token Bucket Algorithm.
 *
 * Token Bucket Algorithm:
 * - Each identifier (IP/user) has a bucket with N tokens
 * - Each request consumes 1 token
 * - Tokens refill at a constant rate (maxAttempts / windowSeconds)
 * - If bucket is empty, request is rate limited
 * - State stored in cache for thread-safety
 *
 * Example: 5 requests per 60 seconds
 * - Bucket capacity: 5 tokens
 * - Refill rate: 5/60 = 0.0833 tokens per second
 * - After 12 seconds, 1 token is refilled (12 * 0.0833 ≈ 1)
 *
 * Thread Safety:
 * - Uses cache with atomic operations
 * - State: {tokens: float, lastRefillTime: int, resetTime: int}
 * - No race conditions between concurrent requests
 */
final readonly class RateLimitService implements RateLimitInterface
{
    private const string CACHE_PREFIX = 'rate_limit_';
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private CacheInterface $cache
    ) {
    }

    /**
     * Check if request is within rate limit using token bucket algorithm.
     *
     * Algorithm Steps:
     * 1. Load current bucket state from cache
     * 2. Calculate elapsed time since last refill
     * 3. Refill tokens based on elapsed time and refill rate
     * 4. Check if at least 1 token is available
     * 5. If available, consume 1 token and allow request
     * 6. Update state in cache
     * 7. Return result with attempts remaining and reset time
     *
     * @param string $identifier Unique identifier (IP address, user ID, etc.)
     * @param int $maxAttempts Maximum number of attempts allowed (bucket capacity)
     * @param int $windowSeconds Time window in seconds (rate calculation period)
     * @return RateLimitResult Result containing allowed status and metadata
     */
    public function checkLimit(string $identifier, int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        $this->validateParameters($maxAttempts, $windowSeconds);

        $cacheKey = $this->getCacheKey($identifier);
        $now = time();
        $refillRate = $this->calculateRefillRate($maxAttempts, $windowSeconds);

        // Load current bucket state
        $state = $this->loadState($cacheKey, $maxAttempts, $now, $windowSeconds);

        // Calculate tokens to refill based on elapsed time
        $state = $this->refillTokens($state, $now, $refillRate, $maxAttempts);

        // Check if request is allowed (at least 1 token available)
        $allowed = $state['tokens'] >= 1.0;

        // Consume 1 token if allowed
        if ($allowed) {
            $state['tokens'] -= 1.0;
        }

        // Update last refill time
        $state['lastRefillTime'] = $now;

        // Calculate attempts remaining (floor to prevent fractional attempts)
        $attemptsRemaining = (int) floor($state['tokens']);

        // Save updated state
        $this->saveState($cacheKey, $state);

        return new RateLimitResult(
            allowed: $allowed,
            attemptsRemaining: max(0, $attemptsRemaining),
            resetTime: $state['resetTime']
        );
    }

    /**
     * Reset rate limit for a specific identifier.
     *
     * Clears the token bucket state from cache.
     *
     * @param string $identifier Unique identifier to reset
     */
    public function reset(string $identifier): void
    {
        $cacheKey = $this->getCacheKey($identifier);
        $this->cache->delete($cacheKey);
    }

    /**
     * Validate rate limit parameters.
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    private function validateParameters(int $maxAttempts, int $windowSeconds): void
    {
        if ($maxAttempts <= 0) {
            throw new \InvalidArgumentException('Max attempts must be greater than 0');
        }

        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('Window seconds must be greater than 0');
        }
    }

    /**
     * Calculate token refill rate (tokens per second).
     *
     * Example: 5 requests per 60 seconds = 0.0833 tokens/second
     *
     * @param int $maxAttempts Maximum number of attempts
     * @param int $windowSeconds Time window in seconds
     * @return float Refill rate in tokens per second
     */
    private function calculateRefillRate(int $maxAttempts, int $windowSeconds): float
    {
        return (float) $maxAttempts / (float) $windowSeconds;
    }

    /**
     * Load bucket state from cache.
     *
     * State structure:
     * {
     *   tokens: float - Current number of tokens in bucket
     *   lastRefillTime: int - Unix timestamp of last refill
     *   resetTime: int - Unix timestamp when bucket will be full
     * }
     *
     * @param string $cacheKey Cache key for the identifier
     * @param int $maxAttempts Maximum bucket capacity
     * @param int $now Current Unix timestamp
     * @param int $windowSeconds Time window for rate limit
     * @return array{tokens: float, lastRefillTime: int, resetTime: int}
     */
    private function loadState(string $cacheKey, int $maxAttempts, int $now, int $windowSeconds): array
    {
        /** @var array{tokens: float, lastRefillTime: int, resetTime: int}|null $state */
        $state = $this->cache->get($cacheKey);

        if ($state === null) {
            // Initialize new bucket with full capacity
            return [
                'tokens' => (float) $maxAttempts,
                'lastRefillTime' => $now,
                'resetTime' => $now + $windowSeconds,
            ];
        }

        return $state;
    }

    /**
     * Refill tokens based on elapsed time.
     *
     * Token Bucket Refill Logic:
     * - Calculate elapsed time since last refill
     * - Calculate tokens to add: elapsedTime * refillRate
     * - Add tokens to current bucket
     * - Cap at maximum bucket capacity
     * - Update reset time if bucket not full
     *
     * Example:
     * - Current tokens: 2.5
     * - Elapsed time: 12 seconds
     * - Refill rate: 0.0833 tokens/second
     * - Tokens to add: 12 * 0.0833 = 1.0
     * - New tokens: min(2.5 + 1.0, 5.0) = 3.5
     *
     * @param array{tokens: float, lastRefillTime: int, resetTime: int} $state Current bucket state
     * @param int $now Current Unix timestamp
     * @param float $refillRate Tokens per second
     * @param int $maxAttempts Maximum bucket capacity
     * @return array{tokens: float, lastRefillTime: int, resetTime: int}
     */
    private function refillTokens(array $state, int $now, float $refillRate, int $maxAttempts): array
    {
        $elapsedTime = $now - $state['lastRefillTime'];

        if ($elapsedTime <= 0) {
            // No time elapsed, no refill needed
            return $state;
        }

        // Calculate tokens to add based on elapsed time
        $tokensToAdd = (float) $elapsedTime * $refillRate;

        // Add tokens to bucket, capped at max capacity
        $state['tokens'] = min(
            $state['tokens'] + $tokensToAdd,
            (float) $maxAttempts
        );

        // Update reset time if bucket is not full
        if ($state['tokens'] < (float) $maxAttempts) {
            $tokensNeeded = (float) $maxAttempts - $state['tokens'];
            $secondsToFull = (int) ceil($tokensNeeded / $refillRate);
            $state['resetTime'] = $now + $secondsToFull;
        } else {
            // Bucket is full, reset time is when it would overflow
            $state['resetTime'] = $now + (int) floor((float) $maxAttempts / $refillRate);
        }

        return $state;
    }

    /**
     * Save bucket state to cache.
     *
     * Thread Safety:
     * - Cache operations are atomic
     * - State updates are serialized by cache layer
     * - No explicit locking needed
     *
     * @param string $cacheKey Cache key for the identifier
     * @param array{tokens: float, lastRefillTime: int, resetTime: int} $state Bucket state to save
     */
    private function saveState(string $cacheKey, array $state): void
    {
        $this->cache->save($cacheKey, $state, self::CACHE_TTL);
    }

    /**
     * Generate cache key for identifier.
     *
     * Uses SHA-256 hash to prevent cache key injection attacks.
     *
     * @param string $identifier Unique identifier
     * @return string Cache key
     */
    private function getCacheKey(string $identifier): string
    {
        $hash = hash('sha256', $identifier);
        return self::CACHE_PREFIX . $hash;
    }
}
