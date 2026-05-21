<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\ErrorCodes;

/**
 * Value Object representing password complexity validation.
 *
 * Security Requirements (OWASP Compliant):
 * - Minimum 12 characters (increased from 8 for enhanced security)
 * - At least one uppercase letter (A-Z)
 * - At least one lowercase letter (a-z)
 * - At least one digit (0-9)
 * - At least one special character (!@#$%^&*()_+-=[]{}|;:,.<>?)
 *
 * Why These Rules:
 * - 12 characters: Provides significant resistance to brute-force attacks
 * - Mixed case: Exponentially increases keyspace for attackers
 * - Digits: Prevents dictionary-only attacks
 * - Special chars: Further increases entropy and complexity
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class PasswordComplexity
{
    private const int MIN_LENGTH = 12;
    private const int MAX_LENGTH = 128;
    private const string UPPERCASE_PATTERN = '/[A-Z]/';
    private const string LOWERCASE_PATTERN = '/[a-z]/';
    private const string DIGIT_PATTERN = '/[0-9]/';
    private const string SPECIAL_CHAR_PATTERN = '/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/';

    /**
     * Private constructor to enforce factory method usage.
     *
     * @param string $value
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Create PasswordComplexity from plaintext password.
     *
     * @param string $password The plaintext password to validate
     * @return self New instance with validated password
     * @throws ValidationException If any complexity requirement fails
     */
    public static function fromPlaintext(string $password): self
    {
        $errors = [];
        $trimmed = trim($password);

        if ($trimmed === '') {
            throw ValidationException::required('password', ErrorCodes::USER_VALIDATION_PASSWORD);
        }

        $length = strlen($trimmed);

        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Must be at least %d characters', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Must not exceed %d characters', self::MAX_LENGTH);
        }

        if (preg_match(self::UPPERCASE_PATTERN, $trimmed) !== 1) {
            $errors[] = 'Must contain at least one uppercase letter (A-Z)';
        }

        if (preg_match(self::LOWERCASE_PATTERN, $trimmed) !== 1) {
            $errors[] = 'Must contain at least one lowercase letter (a-z)';
        }

        if (preg_match(self::DIGIT_PATTERN, $trimmed) !== 1) {
            $errors[] = 'Must contain at least one digit (0-9)';
        }

        if (preg_match(self::SPECIAL_CHAR_PATTERN, $trimmed) !== 1) {
            $errors[] = 'Must contain at least one special character (!@#$%^&*()_+-=[]{}|;:,.<>?)';
        }

        if (count($errors) > 0) {
            throw ValidationException::withErrors(
                ['password' => $errors],
                ErrorCodes::USER_VALIDATION_PASSWORD
            );
        }

        return new self($trimmed);
    }

    /**
     * Get the validated password value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the password length.
     *
     * @return int
     */
    public function getLength(): int
    {
        return strlen($this->value);
    }

    /**
     * Check if password meets minimum length requirement.
     *
     * @return bool
     */
    public function meetsMinimumLength(): bool
    {
        return strlen($this->value) >= self::MIN_LENGTH;
    }

    /**
     * Check if password contains uppercase letter.
     *
     * @return bool
     */
    public function hasUppercase(): bool
    {
        return preg_match(self::UPPERCASE_PATTERN, $this->value) === 1;
    }

    /**
     * Check if password contains lowercase letter.
     *
     * @return bool
     */
    public function hasLowercase(): bool
    {
        return preg_match(self::LOWERCASE_PATTERN, $this->value) === 1;
    }

    /**
     * Check if password contains digit.
     *
     * @return bool
     */
    public function hasDigit(): bool
    {
        return preg_match(self::DIGIT_PATTERN, $this->value) === 1;
    }

    /**
     * Check if password contains special character.
     *
     * @return bool
     */
    public function hasSpecialCharacter(): bool
    {
        return preg_match(self::SPECIAL_CHAR_PATTERN, $this->value) === 1;
    }
}
