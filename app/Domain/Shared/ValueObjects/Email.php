<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing an email address.
 *
 * Ensures that all email addresses in the system are:
 * - Valid format according to RFC standards
 * - Normalized (lowercased)
 * - Immutable once created
 * - Type-safe
 *
 * Why a Value Object for Email:
 * - Centralizes email validation logic
 * - Prevents invalid emails from entering the domain
 * - Makes intent clear (Email vs string)
 * - Enables future enhancements (e.g., domain validation, blacklists)
 *
 * Usage Example:
 * ```php
 * $email = Email::fromString('user@example.com');
 * $email->getValue(); // "user@example.com"
 * ```
 *
 * @package App\Domain\Shared\ValueObjects
 */
final readonly class Email
{
    /**
     * The normalized email address.
     */
    private string $value;

    /**
     * Create a new Email value object.
     *
     * @param string $email The email address
     * @throws ValidationException If email format is invalid
     */
    private function __construct(string $email)
    {
        $normalized = trim(strtolower($email));

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::invalidFormat('email', 'valid email address (e.g., user@example.com)');
        }

        $this->value = $normalized;
    }

    /**
     * Create Email from string.
     *
     * @param string $email The email address
     * @throws ValidationException If validation fails
     */
    public static function fromString(string $email): self
    {
        return new self($email);
    }

    /**
     * Get the email address value.
     *
     * @return string The normalized email address
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the domain part of the email.
     *
     * @return string The domain (e.g., "example.com")
     */
    public function getDomain(): string
    {
        $atPosition = strpos($this->value, '@');
        if ($atPosition === false) {
            return '';
        }

        return substr($this->value, $atPosition + 1);
    }

    /**
     * Get the local part of the email.
     *
     * @return string The local part (e.g., "user")
     */
    public function getLocalPart(): string
    {
        $atPosition = strpos($this->value, '@');
        if ($atPosition === false) {
            return '';
        }

        return substr($this->value, 0, $atPosition);
    }

    /**
     * Check if this email equals another.
     *
     * @param Email $other The other email to compare
     * @return bool True if emails are equal
     */
    public function equals(Email $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Convert to string automatically.
     *
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
