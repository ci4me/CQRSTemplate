<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\AccessToken;
use App\Domain\User\ValueObjects\AuthenticationResult;

/**
 * Port interface for authentication services.
 *
 * This interface defines the contract for authentication operations
 * in the User domain. It follows the Hexagonal Architecture pattern
 * (Ports & Adapters), allowing the domain to remain independent of
 * specific authentication implementations.
 *
 * Implementations might use:
 * - JWT tokens
 * - OAuth2
 * - Session-based authentication
 * - API keys
 *
 * Business Rules Enforced:
 * - Only valid credentials generate tokens
 * - Tokens have expiration dates
 * - Invalid tokens return null user
 * - Token validation is stateless
 *
 * Why Port Interface:
 * - Domain doesn't depend on specific auth library (JWT, Firebase, etc.)
 * - Easy to swap implementations (JWT -> OAuth2)
 * - Testable with mocks
 * - Clear boundary between domain and infrastructure
 *
 * Usage Example:
 * ```php
 * // In command handler
 * $result = $this->authService->authenticate($user, $plainPassword);
 * if ($result->isSuccess()) {
 *     $token = $result->getAccessToken();
 * }
 *
 * // Validate token
 * $user = $this->authService->validateToken($tokenString);
 * if ($user !== null) {
 *     // Token is valid, user is authenticated
 * }
 * ```
 *
 * @package App\Domain\User\Ports
 */
interface AuthenticationServiceInterface
{
    /**
     * Authenticate user with password and generate tokens.
     *
     * Verifies the provided password against the user's hashed password
     * and generates access and refresh tokens if authentication succeeds.
     *
     * Business Rules:
     * - User must be active (not suspended/locked)
     * - Password must match stored hash
     * - Generates new access token on success
     * - Failed authentication increments failed login counter
     *
     * @param User $user The user to authenticate
     * @param string $password The plaintext password to verify
     * @return AuthenticationResult Result containing tokens or error
     */
    public function authenticate(User $user, string $password): AuthenticationResult;

    /**
     * Validate access token and return associated user.
     *
     * Validates the token signature, expiration, and returns the
     * authenticated user if the token is valid.
     *
     * Business Rules:
     * - Token must not be expired
     * - Token signature must be valid
     * - User associated with token must exist and be active
     *
     * @param string $token The access token to validate
     * @return User|null The authenticated user, or null if token is invalid
     */
    public function validateToken(string $token): ?User;

    /**
     * Generate access token for user.
     *
     * Creates a new access token for the given user with a default
     * expiration time (e.g., 1 hour).
     *
     * Business Rules:
     * - Token contains user ID for identification
     * - Token has expiration timestamp
     * - Token is cryptographically signed
     *
     * @param User $user The user to generate token for
     * @return AccessToken The generated access token with expiration
     */
    public function generateToken(User $user): AccessToken;
}
