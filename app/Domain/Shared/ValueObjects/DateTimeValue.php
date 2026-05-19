<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Value Object representing a date and time.
 *
 * Wraps PHP's DateTimeImmutable to provide:
 * - Guaranteed immutability
 * - Domain-specific date operations
 * - Consistent formatting
 * - Type safety
 *
 * Why DateTimeImmutable:
 * Using DateTimeImmutable instead of DateTime prevents accidental
 * mutations and makes the code more predictable and thread-safe.
 *
 * Usage Example:
 * ```php
 * $now = DateTimeValue::now();
 * $date = DateTimeValue::fromString('2025-01-01 10:30:00');
 * $date->format('Y-m-d'); // "2025-01-01"
 * ```
 *
 * @package App\Domain\Shared\ValueObjects
 */
final readonly class DateTimeValue
{
    /**
     * The immutable datetime instance.
     */
    private DateTimeImmutable $value;

    /**
     * Create a new DateTimeValue.
     *
     * @param DateTimeImmutable $dateTime The datetime instance
     */
    private function __construct(DateTimeImmutable $dateTime)
    {
        $this->value = $dateTime;
    }

    /**
     * Create from current time.
     *
     */
    public static function now(): self
    {
        return new self(new DateTimeImmutable());
    }

    /**
     * Create from string.
     *
     * @param string $datetime The datetime string (e.g., "2025-01-01 10:30:00")
     * @throws ValidationException If format is invalid
     */
    public static function fromString(string $datetime): self
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);

        if ($date === false) {
            throw ValidationException::invalidFormat('datetime', 'Y-m-d H:i:s (e.g., 2025-01-01 10:30:00)');
        }

        return new self($date);
    }

    /**
     * Create from DateTimeInterface.
     *
     * @param DateTimeInterface $datetime The datetime instance
     */
    public static function fromDateTime(DateTimeInterface $datetime): self
    {
        if ($datetime instanceof DateTimeImmutable) {
            return new self($datetime);
        }

        return new self(DateTimeImmutable::createFromInterface($datetime));
    }

    /**
     * Get the underlying DateTimeImmutable.
     *
     */
    public function getValue(): DateTimeImmutable
    {
        return $this->value;
    }

    /**
     * Format the datetime.
     *
     * @param string $format The format string (default: Y-m-d H:i:s)
     * @return string Formatted datetime
     */
    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->value->format($format);
    }

    /**
     * Check if this datetime equals another.
     *
     * @param DateTimeValue $other The other datetime to compare
     * @return bool True if datetimes are equal
     */
    public function equals(DateTimeValue $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Check if this datetime is before another.
     *
     * @param DateTimeValue $other The other datetime to compare
     * @return bool True if this is before
     */
    public function isBefore(DateTimeValue $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Check if this datetime is after another.
     *
     * @param DateTimeValue $other The other datetime to compare
     * @return bool True if this is after
     */
    public function isAfter(DateTimeValue $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Convert to string.
     *
     */
    public function __toString(): string
    {
        return $this->format();
    }
}
