<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

use App\Domain\User\Entities\User;

/**
 * Token Generator Port Interface.
 *
 * This interface defines the contract for JWT token generation and validation.
 * It represents a port in Hexagonal Architecture, allowing the domain layer
 * to remain independent of specific JWT library implementations.
 *
 * Why This Is a Port:
 * The domain layer needs to generate authentication tokens but should not
 * depend on a specific JWT library (Firebase JWT, Lcobucci, etc.). This
 * interface defines what the domain needs, and infrastructure provides
 * the concrete implementation.
 *
 * Hexagonal Architecture Pattern:
 * Domain Layer (this interface) → Infrastructure Layer (Firebase JWT adapter)
 *
 * Security Considerations:
 * - Access tokens should be short-lived (15-60 minutes)
 * - Refresh tokens should be longer-lived (7-30 days)
 * - Tokens must include user ID, email, and role in payload
 * - Token validation must verify signature and expiration
 * - Failed validation should throw exceptions (not return false)
 *
 * Token Payload Structure:
 * Access Token: { sub: userId, email: string, role: string, iat: timestamp, exp: timestamp }
 * Refresh Token: { sub: userId, type: 'refresh', iat: timestamp, exp: timestamp }
 *
 * Implementation Requirements:
 * 1. Use strong cryptographic signatures (RS256 or HS256 with 256-bit keys)
 * 2. Include issued-at (iat) and expiration (exp) claims
 * 3. Validate token signature before returning payload
 * 4. Throw domain exceptions on validation failures
 * 5. Never expose secret keys in logs or exceptions
 *
 * Usage Example:
 * ```php
 * // In LoginCommandHandler
 * $accessToken = $this->tokenGenerator->generateAccessToken($user);
 * $refreshToken = $this->tokenGenerator->generateRefreshToken($user);
 *
 * // In AuthMiddleware
 * try {
 *     $payload = $this->tokenGenerator->validateToken($token);
 *     $userId = $payload['sub'];
 * } catch (InvalidTokenException $e) {
 *     // Handle invalid token
 * }
 * ```
 *
 * Why Not Use CodeIgniter's Shield Library:
 * - This template is framework-agnostic at the domain level
 * - Shield is tightly coupled to CodeIgniter 4
 * - This approach allows JWT implementation to change without domain changes
 * - Easier to test with mock implementations
 *
 * @package App\Domain\User\Ports
 */
interface TokenGeneratorInterface
{
    /**
     * Generate a JWT access token for the given user.
     *
     * Access tokens are short-lived tokens used for API authentication.
     * They contain user identity claims (id, email, role) and should
     * expire quickly to minimize security risk if compromised.
     *
     * Token Payload Requirements:
     * - sub (subject): User ID
     * - email: User email address
     * - role: User role (Admin, Customer, etc.)
     * - iat (issued at): Current timestamp
     * - exp (expiration): iat + access token lifetime
     *
     * Recommended Expiration: 15-60 minutes
     *
     * Security Notes:
     * - Token is signed but not encrypted (don't include sensitive data)
     * - Client stores token (localStorage, cookie, etc.)
     * - Server validates signature on each request
     *
     * @param User $user The authenticated user
     * @return string JWT access token (signed, base64-encoded)
     */
    public function generateAccessToken(User $user): string;

    /**
     * Generate a JWT refresh token for the given user.
     *
     * Refresh tokens are long-lived tokens used to obtain new access tokens
     * without requiring the user to re-authenticate. They should contain
     * minimal claims and be rotated on use.
     *
     * Token Payload Requirements:
     * - sub (subject): User ID
     * - type: 'refresh' (distinguishes from access tokens)
     * - iat (issued at): Current timestamp
     * - exp (expiration): iat + refresh token lifetime
     *
     * Recommended Expiration: 7-30 days
     *
     * Security Notes:
     * - Refresh tokens should be stored securely (HTTP-only cookie recommended)
     * - Implement token rotation: invalidate old refresh token when issuing new one
     * - Consider storing refresh token hash in database for revocation
     * - Never send refresh token in URL or query parameters
     *
     * Best Practice: Rotate on Use
     * When a refresh token is used to get a new access token, also issue
     * a new refresh token and invalidate the old one. This limits exposure
     * if a refresh token is compromised.
     *
     * @param User $user The authenticated user
     * @return string JWT refresh token (signed, base64-encoded)
     */
    public function generateRefreshToken(User $user): string;

    /**
     * Validate a JWT token and return its payload.
     *
     * This method verifies the token's signature, checks expiration,
     * and returns the decoded payload. It MUST throw exceptions for
     * any validation failures.
     *
     * Validation Steps:
     * 1. Decode JWT structure (header, payload, signature)
     * 2. Verify signature matches payload and header
     * 3. Check expiration claim (exp)
     * 4. Check not-before claim if present (nbf)
     * 5. Optionally verify issuer (iss) and audience (aud)
     *
     * Exceptions to Throw:
     * - InvalidTokenException: Malformed token, invalid signature
     * - TokenExpiredException: Token exp claim is in the past
     * - DomainException: Other validation failures
     *
     * Security Notes:
     * - Always verify signature before trusting payload
     * - Use constant-time comparison for signature verification
     * - Never return partial results on validation failure
     * - Log validation failures for security monitoring
     *
     * @param string $token The JWT token to validate
     * @return array<string, mixed> Decoded token payload (associative array)
     * @throws \App\Domain\Shared\Exceptions\ValidationException If token is invalid
     * @throws \App\Domain\Shared\Exceptions\DomainException If token is expired or malformed
     */
    public function validateToken(string $token): array;

    /**
     * Extract payload from a JWT token without full validation.
     *
     * This method decodes the JWT payload WITHOUT verifying the signature
     * or expiration. It should ONLY be used when you need to inspect a
     * token before deciding whether to validate it (e.g., checking token type).
     *
     * WARNING: NEVER trust this payload for authentication or authorization!
     * The token could be forged or tampered with. Always use validateToken()
     * for security-critical operations.
     *
     * Use Cases:
     * - Determine token type before validation (access vs refresh)
     * - Extract user ID for logging before validation
     * - Debug token contents in development
     * - Display token expiration in UI
     *
     * Implementation Note:
     * JWT payload is the middle segment of the token (base64-decoded).
     * This method simply decodes it without cryptographic verification.
     *
     * Security Warning:
     * ```php
     * // ❌ DANGEROUS - Don't use for authentication
     * $payload = $tokenGenerator->getTokenPayload($token);
     * $userId = $payload['sub'];  // Could be forged!
     *
     * // ✅ SAFE - Always validate first
     * $payload = $tokenGenerator->validateToken($token);
     * $userId = $payload['sub'];  // Verified signature
     * ```
     *
     * @param string $token The JWT token to decode
     * @return array<string, mixed> Decoded token payload (NOT VERIFIED)
     * @throws \App\Domain\Shared\Exceptions\DomainException If token is malformed
     */
    public function getTokenPayload(string $token): array;
}
