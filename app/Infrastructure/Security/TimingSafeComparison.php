<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Timing-Safe Comparison Utilities.
 *
 * Provides constant-time comparison functions to prevent timing attacks.
 * Timing attacks exploit response time differences to extract sensitive information.
 *
 * SECURITY: Always use these functions when comparing:
 * - Password hashes
 * - API tokens
 * - Session identifiers
 * - Cryptographic signatures
 * - Any secret values
 *
 * Attack Example:
 * ```
 * // VULNERABLE CODE (timing attack possible)
 * if ($userToken === $expectedToken) { ... }
 *
 * // Attack: Measure response time for each character position
 * // "a..." -> 1ms (fails at char 0)
 * // "A..." -> 2ms (fails at char 1) <- character 0 matched!
 * // Attacker gradually discovers token character by character
 * ```
 *
 * @see https://owasp.org/www-community/attacks/Timing_attack
 * @see https://www.php.net/manual/en/function.hash-equals.php
 */
final class TimingSafeComparison
{
    /**
     * Compare two strings in constant time.
     *
     * Uses PHP's hash_equals() which implements constant-time comparison
     * by always comparing the full length, regardless of where differences occur.
     *
     * @param string $known The known (expected) value
     * @param string $user The user-provided value to verify
     * @return bool True if strings are identical, false otherwise
     *
     * @example
     * ```php
     * // Correct usage:
     * if (TimingSafeComparison::equals($storedHash, $providedHash)) {
     *     // Authentication successful
     * }
     *
     * // NEVER use === for secrets:
     * if ($storedHash === $providedHash) { // ❌ VULNERABLE to timing attack
     * }
     * ```
     */
    public static function equals(string $known, string $user): bool
    {
        // PHP's hash_equals uses constant-time comparison
        // Always processes full string length regardless of match position
        return hash_equals($known, $user);
    }

    /**
     * Compare two hexadecimal token strings in constant time.
     *
     * Validates hex format before comparison to prevent invalid input.
     * Normalizes case to ensure case-insensitive comparison.
     *
     * @param string $knownToken The known token (stored value)
     * @param string $userToken The user-provided token to verify
     * @return bool True if tokens are identical (case-insensitive), false otherwise
     *
     * @example
     * ```php
     * $apiToken = 'A1B2C3D4E5F6...'; // Stored API token
     * $providedToken = $_SERVER['HTTP_X_API_TOKEN'];
     *
     * if (TimingSafeComparison::equalsToken($apiToken, $providedToken)) {
     *     // API authentication successful
     * }
     * ```
     */
    public static function equalsToken(string $knownToken, string $userToken): bool
    {
        // Validate hex format (prevents invalid input attacks)
        if (!ctype_xdigit($knownToken) || !ctype_xdigit($userToken)) {
            return false;
        }

        // Normalize to lowercase for case-insensitive comparison
        // hash_equals is case-sensitive, so we must normalize first
        $knownLower = strtolower($knownToken);
        $userLower = strtolower($userToken);

        return hash_equals($knownLower, $userLower);
    }

    /**
     * Compare two JWT tokens in constant time.
     *
     * JWTs are base64url-encoded, this method handles proper comparison
     * including signature verification timing resistance.
     *
     * @param string $knownJwt The known JWT (stored or expected value)
     * @param string $userJwt The user-provided JWT to verify
     * @return bool True if JWTs are identical, false otherwise
     *
     * @example
     * ```php
     * $storedRefreshToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6...';
     * $providedRefreshToken = $_POST['refresh_token'];
     *
     * if (TimingSafeComparison::equalsJwt($storedRefreshToken, $providedRefreshToken)) {
     *     // Token refresh successful
     * }
     * ```
     */
    public static function equalsJwt(string $knownJwt, string $userJwt): bool
    {
        // JWT format: header.payload.signature (all base64url-encoded)
        // Each part contains dots, but hash_equals handles this correctly

        // Validate basic JWT structure (must have 2 dots)
        if (substr_count($knownJwt, '.') !== 2 || substr_count($userJwt, '.') !== 2) {
            return false;
        }

        return hash_equals($knownJwt, $userJwt);
    }

    /**
     * Compare two hashed values with length normalization.
     *
     * When comparing hashes of different algorithms (e.g., SHA256 vs SHA512),
     * length differences can leak information. This method normalizes lengths
     * before comparison to prevent length-based timing attacks.
     *
     * @param string $knownHash The known hash value
     * @param string $userHash The user-provided hash to verify
     * @return bool True if hashes are identical, false otherwise
     *
     * @example
     * ```php
     * // Useful when migrating hash algorithms
     * $storedHash = hash('sha256', $data); // 64 characters
     * $providedHash = $_POST['signature']; // Unknown length
     *
     * if (TimingSafeComparison::equalsHash($storedHash, $providedHash)) {
     *     // Signature valid
     * }
     * ```
     */
    public static function equalsHash(string $knownHash, string $userHash): bool
    {
        // If lengths differ significantly, normalize to prevent length leakage
        // hash_equals already handles this internally, but we make it explicit
        $knownLength = strlen($knownHash);
        $userLength = strlen($userHash);

        if ($knownLength !== $userLength) {
            // Perform comparison anyway to avoid early return timing leak
            // This will always return false, but takes constant time
            $normalized = str_pad($userHash, $knownLength, "\0");
            // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
            $_ = hash_equals($knownHash, $normalized); // Intentionally discarded for timing
            return false;
        }

        return hash_equals($knownHash, $userHash);
    }

    /**
     * Prevent instantiation (utility class).
     */
    private function __construct()
    {
        // Static utility class - no instances allowed
    }
}
