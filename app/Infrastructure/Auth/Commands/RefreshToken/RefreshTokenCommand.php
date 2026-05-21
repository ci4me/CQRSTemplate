<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\RefreshToken;

/**
 * Refresh Token Command.
 *
 * Exchanges a valid refresh token for new access + refresh tokens.
 * Implements token rotation for security.
 *
 * @package App\Infrastructure\Auth\Commands\RefreshToken
 */
final readonly class RefreshTokenCommand
{
    /**
     * __construct.
     *
     * @param string $refreshToken
     */
    public function __construct(
        public string $refreshToken
    ) {
    }
}
