<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\ErrorCodes;

/**
 * Value Object representing an email address.
 *
 * Business Rules:
 * - Email must be a valid format according to RFC standards
 * - Email is normalized to lowercase
 * - Email cannot be empty
 *
 * Why a Value Object for Email:
 * - Centralizes email validation logic
 * - Prevents invalid emails from entering the domain
 * - Makes code self-documenting (Email vs string)
 * - Enables consistent validation across create/update operations
 * - Enforces format correctness at construction time
 *
 * Immutability:
 * Once created, an Email cannot be changed. To get a different
 * email, create a new Email instance.
 *
 * Usage Example:
 * ```php
 * $email = Email::fromString('user@example.com');
 * $email->getValue(); // "user@example.com"
 * $email->getDomain(); // "example.com"
 * ```
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class Email
{
    /**
     * The validated and normalized email address.
     */
    private string $value;

    /**
     * Create a new Email value object.
     *
     * @param string $email The email address
     * @throws ValidationException If validation fails
     */
    private function __construct(string $email)
    {
        $normalized = trim(strtolower($email));

        if ($normalized === '') {
            throw ValidationException::required('email', ErrorCodes::USER_VALIDATION_EMAIL);
        }

        if (!$this->isValidFormat($normalized)) {
            throw ValidationException::invalidFormat(
                'email',
                'valid email address (e.g., user@example.com)',
                ErrorCodes::USER_VALIDATION_EMAIL
            );
        }

        $this->value = $normalized;
    }

    /**
     * Create Email from string.
     *
     * @param string $email The email address
     * @return self The Email value object
     * @throws ValidationException If validation fails
     */
    public static function fromString(string $email): self
    {
        return new self($email);
    }

    /**
     * Get the email address value.
     *
     * @return string The validated email address
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
        $parts = explode('@', $this->value);

        return $parts[1] ?? '';
    }

    /**
     * Get the local part of the email.
     *
     * @return string The local part (before @)
     */
    public function getLocalPart(): string
    {
        $parts = explode('@', $this->value);

        return $parts[0];
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
     * Check if this email equals a string.
     *
     * Useful for checking uniqueness.
     *
     * @param string $email The email to compare
     * @return bool True if emails are equal
     */
    public function equalsString(string $email): bool
    {
        return $this->value === trim(strtolower($email));
    }

    /**
     * Convert to string automatically.
     *
     * @return string The email address
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Validate email format using PHP's filter_var.
     *
     * @param string $email The email to validate
     * @return bool True if valid format
     */
    private function isValidFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
