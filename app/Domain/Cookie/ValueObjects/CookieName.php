<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a Cookie name.
 *
 * Business Rules:
 * - Name must be between 3 and 100 characters
 * - Name is trimmed of whitespace
 * - Name cannot be empty after trimming
 *
 * Why a Value Object for Cookie Name:
 * - Centralizes name validation logic
 * - Prevents invalid names from entering the domain
 * - Makes code self-documenting (CookieName vs string)
 * - Enables consistent validation across create/update operations
 *
 * Immutability:
 * Once created, a CookieName cannot be changed. To get a different
 * name, create a new CookieName instance.
 *
 * Usage Example:
 * ```php
 * $name = CookieName::fromString('Chocolate Chip');
 * $name->getValue(); // "Chocolate Chip"
 * $name->getLength(); // 14
 * ```
 *
 * @package App\Domain\Cookie\ValueObjects
 */
final readonly class CookieName
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;

    /**
     * The validated and normalized cookie name.
     */
    private string $value;

    /**
     * Create a new CookieName value object.
     *
     * @param string $name The cookie name
     * @throws ValidationException If validation fails
     */
    private function __construct(string $name)
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $length = mb_strlen($normalized);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::fieldTooShort('name', self::MIN_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        if ($length > self::MAX_LENGTH) {
            throw ValidationException::fieldTooLong('name', self::MAX_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $this->value = $normalized;
    }

    /**
     * Create CookieName from string.
     *
     * @param string $name The cookie name
     * @throws ValidationException If validation fails
     */
    public static function fromString(string $name): self
    {
        return new self($name);
    }

    /**
     * Get the cookie name value.
     *
     * @return string The validated cookie name
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the length of the cookie name.
     *
     * @return int The character count
     */
    public function getLength(): int
    {
        return mb_strlen($this->value);
    }

    /**
     * Check if this name equals another.
     *
     * @param CookieName $other The other name to compare
     * @return bool True if names are equal
     */
    public function equals(CookieName $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Check if this name equals a string (case-insensitive).
     *
     * Useful for checking uniqueness.
     *
     * @param string $name The name to compare
     * @return bool True if names are equal (case-insensitive)
     */
    public function equalsIgnoreCase(string $name): bool
    {
        return strtolower($this->value) === strtolower(trim($name));
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
