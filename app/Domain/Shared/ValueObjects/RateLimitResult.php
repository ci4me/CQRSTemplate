<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * Rate Limit Result DTO.
 *
 * Lives in the Domain layer because the {@see \App\Domain\User\Ports\RateLimitInterface}
 * port returns it — a domain port cannot type-hint an infrastructure class
 * without flipping the dependency-direction rule (domain MUST NOT depend on
 * infrastructure). The legacy location at
 * `App\Infrastructure\Auth\ValueObjects\RateLimitResult` is preserved as a
 * thin shim that extends this class so existing call sites compile unchanged.
 *
 * Immutable data: every field is set at construction and the only methods
 * are accessors / pure derivations.
 */
class RateLimitResult
{
    /**
     * @param bool $allowed           Whether the request is allowed
     * @param int  $attemptsRemaining Number of attempts remaining in the current window
     * @param int  $resetTime         Unix timestamp when the rate limit will reset
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly bool $allowed,
        private readonly int $attemptsRemaining,
        private readonly int $resetTime
    ) {
        if ($attemptsRemaining < 0) {
            throw new \InvalidArgumentException('Attempts remaining cannot be negative');
        }

        if ($resetTime < 0) {
            throw new \InvalidArgumentException('Reset time cannot be negative');
        }
    }

    /**
     * isAllowed.
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * getAttemptsRemaining.
     *
     * @return int
     */
    public function getAttemptsRemaining(): int
    {
        return $this->attemptsRemaining;
    }

    /**
     * getResetTime.
     *
     * @return int
     */
    public function getResetTime(): int
    {
        return $this->resetTime;
    }

    /**
     * getSecondsUntilReset.
     *
     * @return int
     */
    public function getSecondsUntilReset(): int
    {
        return max(0, $this->resetTime - time());
    }

    /**
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
