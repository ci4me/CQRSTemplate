<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a securely hashed password.
 *
 * Security Considerations:
 * - Uses PASSWORD_ARGON2ID algorithm (strongest available, resistant to GPU attacks)
 * - Password verification uses password_verify() for constant-time comparison
 * - Prevents timing attacks where response time reveals password information
 * - Immutable - once created, cannot be modified
 * - Never exposes plaintext passwords
 *
 * Business Rules:
 * - Minimum password length: 8 characters
 * - Password cannot be empty
 * - Uses memory-hard hashing to resist brute-force attacks
 *
 * Why Argon2ID:
 * - Winner of Password Hashing Competition (PHC)
 * - Resistant to GPU cracking attacks
 * - Resistant to side-channel timing attacks
 * - Memory-hard (expensive to crack even with specialized hardware)
 * - Hybrid mode (combines data-dependent and data-independent memory access)
 *
 * Usage Example:
 * ```php
 * // Hash new password
 * $hashed = HashedPassword::fromPlaintext('mySecurePassword123');
 * $hash = $hashed->getHash(); // Store in database
 *
 * // Verify password (from login)
 * $stored = HashedPassword::fromHash($hashFromDatabase);
 * if ($stored->verify($userInput)) {
 *     // Password correct
 * }
 * ```
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class HashedPassword
{
    /**
     * The bcrypt/argon2 hash string.
     *
     * This hash is safe to store in the database and never contains
     * the original plaintext password.
     *
     * @var string
     */
    private string $hash;

    /**
     * Private constructor to enforce factory method usage.
     *
     * @param string $hash The hashed password string
     */
    private function __construct(string $hash)
    {
        $this->hash = $hash;
    }

    /**
     * Create from existing hash (when reconstituting from database).
     *
     * @param string $hash The existing password hash
     * @return self
     */
    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    /**
     * Create from plaintext password (hashes it securely).
     *
     * Security Guarantees:
     * - Uses PASSWORD_ARGON2ID (strongest algorithm available in PHP)
     * - Automatic salt generation (unique per password)
     * - Cost parameters tuned for security vs performance
     * - Resistant to rainbow table attacks
     * - Resistant to GPU/ASIC cracking
     *
     * Business Rules (OWASP Compliant):
     * - Password complexity enforced via PasswordComplexity value object
     * - Minimum 12 characters (enhanced security)
     * - Must contain uppercase, lowercase, digit, and special character
     *
     * @param string $plaintext The plaintext password to hash
     * @return self New instance with hashed password
     * @throws ValidationException If password complexity validation fails
     * @throws \RuntimeException If hashing algorithm fails
     */
    public static function fromPlaintext(string $plaintext): self
    {
        // SECURITY: Enforce password complexity rules before hashing
        // This prevents weak passwords from being stored in the system
        // Complexity requirements: 12+ chars, uppercase, lowercase, digit, special char
        $complexity = PasswordComplexity::fromPlaintext($plaintext);
        $validated = $complexity->getValue();

        // PASSWORD_ARGON2ID returns a non-empty hash for validated input
        $hash = password_hash($validated, PASSWORD_ARGON2ID);

        return new self($hash);
    }

    /**
     * Get the password hash.
     *
     * @return string The hashed password
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Verify if plaintext password matches this hash.
     *
     * Security Critical - Constant-Time Comparison:
     * Uses password_verify() which performs constant-time comparison
     * to prevent timing attacks. This means verification takes the same
     * amount of time regardless of whether the password is correct or not,
     * preventing attackers from using response time to guess passwords.
     *
     * Why Constant-Time Matters:
     * Without constant-time comparison, attackers could:
     * 1. Submit password guesses
     * 2. Measure response time
     * 3. Infer which characters are correct based on timing differences
     * 4. Gradually reveal the password character by character
     *
     * @param string $plaintext The plaintext password to verify
     * @return bool True if password matches, false otherwise
     */
    public function verify(string $plaintext): bool
    {
        return password_verify($plaintext, $this->hash);
    }

    /**
     * Check if hash needs rehashing (algorithm upgraded).
     *
     * @return bool True if rehashing recommended
     */
    public function needsRehash(): bool
    {
        return password_needs_rehash($this->hash, PASSWORD_ARGON2ID);
    }
}
