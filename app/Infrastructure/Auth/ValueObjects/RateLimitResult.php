<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\ValueObjects;

/**
 * Rate Limit Result DTO.
 *
 * Immutable data transfer object containing rate limit check results.
 */
final readonly class RateLimitResult
{
    /**
     * Create a new rate limit result.
     *
     * @param bool $allowed Whether the request is allowed
     * @param int $attemptsRemaining Number of attempts remaining in the current window
     * @param int $resetTime Unix timestamp when the rate limit will reset
     */
    public function __construct(
        private bool $allowed,
        private int $attemptsRemaining,
        private int $resetTime
    ) {
        if ($attemptsRemaining < 0) {
            throw new \InvalidArgumentException('Attempts remaining cannot be negative');
        }

        if ($resetTime < 0) {
            throw new \InvalidArgumentException('Reset time cannot be negative');
        }
    }

    /**
     * Check if the request is allowed.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Get the number of attempts remaining.
     */
    public function getAttemptsRemaining(): int
    {
        return $this->attemptsRemaining;
    }

    /**
     * Get the Unix timestamp when the rate limit will reset.
     */
    public function getResetTime(): int
    {
        return $this->resetTime;
    }

    /**
     * Get the number of seconds until the rate limit resets.
     */
    public function getSecondsUntilReset(): int
    {
        $now = time();
        $diff = $this->resetTime - $now;
        return max(0, $diff);
    }

    /**
     * Convert to array representation.
     *
     * @return array{allowed: bool, attemptsRemaining: int, resetTime: int, secondsUntilReset: int}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'attemptsRemaining' => $this->attemptsRemaining,
            'resetTime' => $this->resetTime,
            'secondsUntilReset' => $this->getSecondsUntilReset(),
        ];
    }
}
