<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\ValueObjects;

use App\Domain\User\Entities\User;

/**
 * Authentication Result Value Object.
 *
 * Immutable result of an authentication attempt containing:
 * - User entity
 * - Access token (JWT)
 * - Refresh token (JWT)
 * - Token expiration timestamp
 *
 * @package App\Infrastructure\Auth\ValueObjects
 */
final readonly class AuthenticationResult
{
    /**
     * __construct.
     *
     * @param User   $user
     * @param string $accessToken
     * @param string $refreshToken
     * @param int    $expiresAt
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function __construct(
        private User $user,
        private string $accessToken,
        private string $refreshToken,
        private int $expiresAt
    ) {
    }

    /**
     * Create authentication result from successful login.
     *
     * @param User   $user         Authenticated user
     * @param string $accessToken  JWT access token
     * @param string $refreshToken JWT refresh token
     * @param int    $expiresAt    Unix timestamp when token expires
     * @return self
     */
    public static function create(
        User $user,
        string $accessToken,
        string $refreshToken,
        int $expiresAt
    ): self {
        return new self($user, $accessToken, $refreshToken, $expiresAt);
    }

    /**
     * getUser.
     *
     * @return User
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * getAccessToken.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * getRefreshToken.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * getExpiresAt.
     *
     * @return int
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user' => [
                'id' => $this->user->getId(),
                'email' => $this->user->getEmail()->getValue(),
                'name' => $this->user->getName()->getValue(),
                'role' => $this->user->getRole()->value,
            ],
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'expires_in' => max(0, $this->expiresAt - time()),
        ];
    }
}
