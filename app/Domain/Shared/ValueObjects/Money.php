<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a monetary amount.
 *
 * This class ensures that all money values in the system are:
 * - Always positive (or zero if explicitly allowed)
 * - Properly formatted with 2 decimal places
 * - Immutable once created
 * - Type-safe (cannot be confused with regular floats)
 *
 * Why a Value Object for Money:
 * - Prevents negative prices
 * - Ensures consistent precision (2 decimal places)
 * - Centralizes money validation logic
 * - Makes intent clear (Money vs float)
 * - Enables comparison logic (equals, greaterThan, etc.)
 *
 * Immutability:
 * Value Objects must be immutable. Once created, they cannot be changed.
 * To get a different value, create a new Money instance.
 *
 * Usage Example:
 * ```php
 * $price = Money::fromFloat(29.99);
 * $price->getValue(); // 29.99
 * $price->toString(); // "29.99"
 * ```
 *
 * @package App\Domain\Shared\ValueObjects
 */
final readonly class Money
{
    /**
     * The monetary value with 2 decimal precision.
     */
    private float $value;

    /**
     * Create a new Money value object.
     *
     * @param float $value The monetary amount
     * @param bool $allowZero Whether to allow zero values (default: true)
     * @throws ValidationException If value is negative or zero (when not allowed)
     */
    private function __construct(float $value, bool $allowZero = true)
    {
        if ($value < 0) {
            throw ValidationException::tooSmall('amount', 0, $value);
        }

        if (!$allowZero && $value === 0.0) {
            throw ValidationException::tooSmall('amount', 0.01, $value);
        }

        // Round to 2 decimal places for consistency
        $this->value = round($value, 2);
    }

    /**
     * Create Money from a float value.
     *
     * @param float $value The monetary amount
     * @param bool $allowZero Whether to allow zero values
     * @throws ValidationException If validation fails
     */
    public static function fromFloat(float $value, bool $allowZero = true): self
    {
        return new self($value, $allowZero);
    }

    /**
     * Create Money from a string value.
     *
     * @param string $value The monetary amount as string (e.g., "29.99")
     * @param bool $allowZero Whether to allow zero values
     * @throws ValidationException If validation fails
     */
    public static function fromString(string $value, bool $allowZero = true): self
    {
        $floatValue = (float) $value;

        return new self($floatValue, $allowZero);
    }

    /**
     * Get the numeric value.
     *
     * @return float The monetary amount
     */
    public function getValue(): float
    {
        return $this->value;
    }

    /**
     * Get string representation with 2 decimal places.
     *
     * @return string Formatted money string (e.g., "29.99")
     */
    public function toString(): string
    {
        return number_format($this->value, 2, '.', '');
    }

    /**
     * Check if this money equals another.
     *
     * @param Money $other The other money to compare
     * @return bool True if values are equal
     */
    public function equals(Money $other): bool
    {
        return abs($this->value - $other->value) < 0.001;
    }

    /**
     * Check if this money is greater than another.
     *
     * @param Money $other The other money to compare
     * @return bool True if this value is greater
     */
    public function greaterThan(Money $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Check if this money is less than another.
     *
     * @param Money $other The other money to compare
     * @return bool True if this value is less
     */
    public function lessThan(Money $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Add another money value.
     *
     * @param Money $other The money to add
     * @return Money New Money instance with sum
     */
    public function add(Money $other): Money
    {
        return new self($this->value + $other->value);
    }

    /**
     * Subtract another money value.
     *
     * @param Money $other The money to subtract
     * @return Money New Money instance with difference
     * @throws ValidationException If result would be negative
     */
    public function subtract(Money $other): Money
    {
        return new self($this->value - $other->value);
    }

    /**
     * Multiply by a factor.
     *
     * @param float|int $multiplier The multiplication factor
     * @return Money New Money instance with product
     */
    public function multiply(float|int $multiplier): Money
    {
        return new self($this->value * $multiplier);
    }

    /**
     * Convert to string automatically.
     *
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
