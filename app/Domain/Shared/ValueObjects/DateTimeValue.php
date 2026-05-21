<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

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
     * Canonical storage timezone. Every DateTimeValue is normalised to UTC
     * on construction so equality and ordering don't depend on the
     * server's local timezone setting (`date.timezone`). Display-time
     * conversions are the caller's responsibility.
     */
    private const string STORAGE_TIMEZONE = 'UTC';

    /**
     * The immutable datetime instance, always in UTC.
     *
     * @var DateTimeImmutable
     */
    private DateTimeImmutable $value;

    /**
     * __construct.
     *
     * @param DateTimeImmutable $dateTime
     */
    private function __construct(DateTimeImmutable $dateTime)
    {
        $this->value = $dateTime->setTimezone(new DateTimeZone(self::STORAGE_TIMEZONE));
    }

    /**
     * Create from current time (UTC).
     *
     * @return self
     */
    public static function now(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone(self::STORAGE_TIMEZONE)));
    }

    /**
     * Create from string.
     *
     * Accepts the canonical `Y-m-d H:i:s` format (assumed UTC) AND the
     * looser ISO-8601 with timezone — the latter is what API clients
     * typically send. Whatever timezone is supplied, the resulting value
     * is normalised to UTC.
     *
     * @param string $datetime The datetime string (e.g., "2025-01-01 10:30:00" or "2025-01-01T10:30:00+02:00")
     * @return self
     * @throws ValidationException If format is invalid
     */
    public static function fromString(string $datetime): self
    {
        $utc = new DateTimeZone(self::STORAGE_TIMEZONE);

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, $utc);
        if ($date === false) {
            // Fallback: ISO-8601 / RFC-3339 with explicit timezone offset.
            try {
                $date = new DateTimeImmutable($datetime);
            } catch (\Throwable) {
                throw ValidationException::invalidFormat(
                    'datetime',
                    'Y-m-d H:i:s or ISO-8601 with timezone offset'
                );
            }
        }

        return new self($date);
    }

    /**
     * Create from DateTimeInterface (normalised to UTC).
     *
     * @param DateTimeInterface $datetime The datetime instance
     * @return self
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
     * @return DateTimeImmutable
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
     * Check if this datetime equals another by instant-in-time, NOT by
     * object identity. PHP's `===` on DateTimeImmutable compares object
     * identity, so two values built from "the same string" would compare
     * as not-equal. We normalise to UTC and compare timestamps — the
     * value-object equality the rest of the domain expects.
     *
     * @param DateTimeValue $other The other datetime to compare
     * @return bool
     */
    public function equals(DateTimeValue $other): bool
    {
        return $this->value->getTimestamp() === $other->value->getTimestamp();
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
     * @return string
     */
    public function __toString(): string
    {
        return $this->format();
    }
}
