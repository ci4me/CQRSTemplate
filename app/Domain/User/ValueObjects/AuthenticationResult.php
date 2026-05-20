<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\User\Entities\User;

/**
 * Value Object representing the result of an authentication attempt.
 *
 * Business Rules:
 * - Result can be either success or failure
 * - Success result contains access token, refresh token, user, and expiration
 * - Failure result contains error message
 * - Result is immutable after creation
 *
 * Why a Value Object for Authentication Result:
 * - Encapsulates authentication outcome data
 * - Type-safe representation of success/failure states
 * - Prevents inconsistent authentication results
 * - Makes authentication handlers more expressive
 * - Couples related authentication data together
 *
 * Immutability:
 * Once created, an AuthenticationResult cannot be changed. Each authentication
 * attempt produces a new AuthenticationResult instance.
 *
 * Usage Example:
 * ```php
 * // Success case
 * $result = AuthenticationResult::success($token, $refreshToken, $user, 3600);
 * $result->isSuccess(); // true
 * $result->getAccessToken(); // AccessToken object
 *
 * // Failure case
 * $result = AuthenticationResult::failure('Invalid credentials');
 * $result->isSuccess(); // false
 * $result->getErrorMessage(); // "Invalid credentials"
 * ```
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class AuthenticationResult
{
    /**
     * Create a new AuthenticationResult value object.
     *
     * @param bool             $success      Whether authentication succeeded
     * @param AccessToken|null $accessToken  The access token (success only)
     * @param AccessToken|null $refreshToken The refresh token (success only)
     * @param User|null        $user         The authenticated user (success only)
     * @param int|null         $expiresIn    Seconds until token expiration (success only)
     * @param string|null      $errorMessage Error message (failure only)
     */
    private function __construct(
        public bool $success,
        public ?AccessToken $accessToken,
        public ?AccessToken $refreshToken,
        public ?User $user,
        public ?int $expiresIn,
        public ?string $errorMessage
    ) {
    }

    /**
     * Create successful authentication result.
     *
     * @param AccessToken $accessToken  The access token
     * @param AccessToken $refreshToken The refresh token
     * @param User        $user         The authenticated user
     * @param int         $expiresIn    Seconds until token expiration
     * @return self Successful authentication result
     */
    public static function success(
        AccessToken $accessToken,
        AccessToken $refreshToken,
        User $user,
        int $expiresIn
    ): self {
        return new self(
            success: true,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            user: $user,
            expiresIn: $expiresIn,
            errorMessage: null
        );
    }

    /**
     * Create failed authentication result.
     *
     * @param string $errorMessage The error message
     * @return self Failed authentication result
     */
    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            accessToken: null,
            refreshToken: null,
            user: null,
            expiresIn: null,
            errorMessage: $errorMessage
        );
    }

    /**
     * Check if authentication was successful.
     *
     * @return bool True if authentication succeeded
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
}
