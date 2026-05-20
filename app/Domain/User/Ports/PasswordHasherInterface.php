<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

/**
 * Password Hasher Interface.
 *
 * Port interface for password hashing operations following Hexagonal Architecture.
 * Defines the contract for securely hashing and verifying passwords.
 *
 * Implementations of this interface handle:
 * - Secure password hashing with appropriate algorithms (e.g., bcrypt, argon2)
 * - Password verification against stored hashes
 * - Hash algorithm migration and upgrades
 *
 * This interface is a Port in the Hexagonal Architecture pattern, allowing
 * the domain layer to remain independent of specific hashing implementations.
 *
 * @package App\Domain\User\Ports
 */
interface PasswordHasherInterface
{
    /**
     * Hash a plain text password.
     *
     * Generates a secure hash of the provided plain text password using
     * the configured hashing algorithm. The resulting hash should be
     * suitable for secure storage in the database.
     *
     * @param string $plaintext Plain text password to hash
     * @return string Securely hashed password
     */
    public function hash(string $plaintext): string;

    /**
     * Verify plain text password against stored hash.
     *
     * Performs a timing-safe comparison of the plain text password
     * against the stored hash to prevent timing attacks.
     *
     * @param string $plaintext Plain text password to verify
     * @param string $hash      Stored password hash to compare against
     * @return bool True if password matches hash, false otherwise
     */
    public function verify(string $plaintext, string $hash): bool;
}
