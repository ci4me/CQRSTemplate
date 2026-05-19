<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a Cookie price.
 *
 * Business Rules:
 * - Price must be greater than zero
 * - Price has a maximum of 2 decimal places
 * - Price is stored and compared as integer minor units to avoid float drift
 *
 * Usage Example:
 * ```php
 * $price = CookiePrice::fromString('2.99');
 * $price->getMinorUnits(); // 299
 * $price->toDecimalString(); // "2.99"
 * ```
 */
final readonly class CookiePrice
{
    private const int MIN_MINOR_UNITS = 1;
    private const int MAX_MINOR_UNITS = 999_999; // 9999.99

    /**
     * Price in minor units (cents for the current UI currency).
     */
    private int $minorUnits;

    private function __construct(int $minorUnits)
    {
        if ($minorUnits < self::MIN_MINOR_UNITS) {
            throw ValidationException::tooSmall(
                'price',
                self::minorUnitsToFloat(self::MIN_MINOR_UNITS),
                self::minorUnitsToFloat($minorUnits),
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }

        if ($minorUnits > self::MAX_MINOR_UNITS) {
            throw ValidationException::outOfRange(
                'price',
                self::minorUnitsToFloat(self::MIN_MINOR_UNITS),
                self::minorUnitsToFloat(self::MAX_MINOR_UNITS),
                self::minorUnitsToFloat($minorUnits),
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }

        $this->minorUnits = $minorUnits;
    }

    /**
     * Create CookiePrice from a decimal string.
     *
     * Floats should stay outside commands and HTTP boundaries. Accept decimal
     * strings instead so invalid precision is rejected instead of rounded.
     */
    public static function fromString(string $price): self
    {
        $trimmed = trim($price);

        if ($trimmed === '') {
            throw ValidationException::required('price', ErrorCodes::COOKIE_VALIDATION_PRICE);
        }

        $cleaned = preg_replace('/^[\$£€¥]\s*/u', '', $trimmed);
        if ($cleaned === null) {
            throw self::invalidFormat();
        }

        $cleaned = trim($cleaned);

        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $cleaned) !== 1) {
            throw self::invalidFormat();
        }

        $isNegative = str_starts_with($cleaned, '-');
        $unsigned = ltrim($cleaned, '-');
        [$major, $minor] = array_pad(explode('.', $unsigned, 2), 2, '');

        $minorUnits = ((int) $major) * 100 + (int) str_pad($minor, 2, '0');
        if ($isNegative) {
            $minorUnits *= -1;
        }

        return new self($minorUnits);
    }

    /**
     * Create CookiePrice from integer minor units.
     */
    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    /**
     * Backwards-compatible factory for legacy code/tests.
     *
     * Prefer fromString() at boundaries because float values may already have
     * lost decimal precision before they reach this method.
     */
    public static function fromFloat(float $price): self
    {
        if (!is_finite($price)) {
            throw self::invalidFormat();
        }

        return new self((int) round($price * 100));
    }

    /**
     * Get the price as a float for legacy consumers.
     */
    public function getValue(): float
    {
        return self::minorUnitsToFloat($this->minorUnits);
    }

    public function getMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Get database-safe decimal representation.
     */
    public function toDecimalString(): string
    {
        return number_format($this->minorUnits / 100, 2, '.', '');
    }

    /**
     * Alias for decimal string representation.
     */
    public function toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Format price as currency string.
     */
    public function format(string $currency = '$'): string
    {
        return $currency . $this->toDecimalString();
    }

    public function equals(CookiePrice $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(CookiePrice $other): bool
    {
        return $this->minorUnits > $other->minorUnits;
    }

    public function isGreaterThan(CookiePrice $other): bool
    {
        return $this->greaterThan($other);
    }

    public function lessThan(CookiePrice $other): bool
    {
        return $this->minorUnits < $other->minorUnits;
    }

    public function isLessThan(CookiePrice $other): bool
    {
        return $this->lessThan($other);
    }

    public function add(CookiePrice $other): CookiePrice
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    public function subtract(CookiePrice $other): CookiePrice
    {
        return new self($this->minorUnits - $other->minorUnits);
    }

    public function multiplyBy(int $quantity): CookiePrice
    {
        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }

        return new self($this->minorUnits * $quantity);
    }

    public function applyDiscount(float $discountPercent): CookiePrice
    {
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw ValidationException::outOfRange(
                'discountPercent',
                0,
                100,
                $discountPercent,
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }

        $discountedMinorUnits = (int) round($this->minorUnits * (100 - $discountPercent) / 100);

        return new self($discountedMinorUnits);
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private static function invalidFormat(): ValidationException
    {
        return ValidationException::invalidFormat(
            'price',
            'a decimal amount with up to 2 decimal places',
            ErrorCodes::COOKIE_VALIDATION_PRICE
        );
    }

    private static function minorUnitsToFloat(int $minorUnits): float
    {
        return $minorUnits / 100;
    }
}
