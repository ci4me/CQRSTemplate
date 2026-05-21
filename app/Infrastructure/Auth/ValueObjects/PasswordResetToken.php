<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\ValueObjects;

/**
 * Password Reset Token Value Object.
 *
 * SECURITY:
 * - Cryptographically secure random token (32 bytes)
 * - SHA-256 hash stored in database (prevents token theft from DB)
 * - Short expiration (1 hour)
 * - Single-use (deleted after password reset)
 *
 * @package App\Infrastructure\Auth\ValueObjects
 */
final readonly class PasswordResetToken
{
    /**
     * __construct.
     *
     * @param string $token
     * @param string $tokenHash
     */
    private function __construct(
        private string $token,
        private string $tokenHash
    ) {
    }

    /**
     * Generate new password reset token.
     *
     * @return self
     */
    public static function generate(): self
    {
        // Generate 32 bytes of cryptographically secure random data
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        return new self($token, $tokenHash);
    }

    /**
     * Reconstitute from raw token (for validation).
     *
     * @param string $token Raw token from email link
     * @return self
     */
    public static function fromToken(string $token): self
    {
        $tokenHash = hash('sha256', $token);
        return new self($token, $tokenHash);
    }

    /**
     * Get raw token (send to user via email).
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Get hashed token (store in database).
     *
     * @return string
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }
}
