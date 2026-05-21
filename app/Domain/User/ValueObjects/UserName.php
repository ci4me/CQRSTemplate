<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Domain\User\ErrorCodes;

/**
 * User Name Value Object.
 *
 * Represents a user's full name with validation rules.
 *
 * Immutability:
 * - Value objects are immutable
 * - All validation happens in constructor
 * - If validation passes, object is guaranteed valid
 *
 * Validation Rules:
 * - Required (not empty)
 * - Minimum 2 characters
 * - Maximum 100 characters
 * - Trimmed of whitespace
 *
 * Why Value Object:
 * - Encapsulates validation logic
 * - Self-documenting type safety
 * - Prevents invalid states
 * - Reusable across domain
 *
 * @package App\Domain\User\ValueObjects
 */
final readonly class UserName
{
    private const int MIN_LENGTH = 2;
    private const int MAX_LENGTH = 100;

    /**
     * Private constructor enforces factory method usage.
     *
     * @param string $value The validated user name
     */
    private function __construct(
        private string $value
    ) {
    }

    /**
     * Create UserName from string with validation.
     *
     * @param string $name The user name to validate
     * @return self Valid UserName instance
     * @throws \InvalidArgumentException If validation fails
     */
    public static function fromString(string $name): self
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'User name is required',
                ErrorCodes::USER_VALIDATION_NAME
            );
        }

        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('User name must be at least %d characters', self::MIN_LENGTH),
                ErrorCodes::USER_VALIDATION_NAME
            );
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('User name must not exceed %d characters', self::MAX_LENGTH),
                ErrorCodes::USER_VALIDATION_NAME
            );
        }

        return new self($trimmed);
    }

    /**
     * Get the name value.
     *
     * @return string The validated user name
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * String representation.
     *
     * @return string The name value
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
