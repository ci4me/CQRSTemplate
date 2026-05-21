<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\ErrorCodes;

/**
 * Value Object representing an access token for authentication.
 *
 * Business Rules:
 * - Token must not be empty
 * - Token has an expiration date
 * - Tokens are immutable once created
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class AccessToken
{
    /**
     * Create a new AccessToken value object.
     *
     * @param string             $token     The access token string
     * @param \DateTimeImmutable $expiresAt The expiration timestamp
     * @throws ValidationException If validation fails
     */
    private function __construct(
        private string $token,
        private \DateTimeImmutable $expiresAt
    ) {
        $normalized = trim($token);

        if ($normalized === '') {
            throw ValidationException::required('token', ErrorCodes::USER_VALIDATION_TOKEN);
        }
    }

    /**
     * Create AccessToken from string with expiration.
     */
    public static function fromString(string $token, \DateTimeImmutable $expiresAt): self
    {
        return new self($token, $expiresAt);
    }

    /**
     * Get the access token value.
     */
    public function getValue(): string
    {
        return $this->token;
    }

    /**
     * Get the token expiration timestamp.
     */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Check if the token is expired.
     */
    public function isExpired(): bool
    {
        $now = new \DateTimeImmutable();
        return $now > $this->expiresAt;
    }

    /**
     * Check if this token equals another.
     */
    public function equals(AccessToken $other): bool
    {
        return $this->token === $other->token
            && $this->expiresAt === $other->expiresAt;
    }

    /**
     * Convert to string automatically.
     */
    public function __toString(): string
    {
        return $this->token;
    }
}
