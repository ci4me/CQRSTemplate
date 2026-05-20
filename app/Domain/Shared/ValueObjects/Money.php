<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Canonical monetary amount value object (D7).
 *
 * Stored as integer minor units (e.g. cents for USD/EUR, yen for JPY) so
 * arithmetic is exact — no float-drift in totals, no rounding surprises.
 * The {@see Currency} carries the ISO-4217 code, decimal count, and
 * display symbol; one Money instance always carries both, so a caller
 * cannot accidentally add USD to EUR.
 *
 * Boundary conversions:
 *  - {@see self::fromMinorUnits()} — what repositories use after reading
 *    an integer column.
 *  - {@see self::fromDecimalString()} — what HTTP layers / CSVs use; rejects
 *    excessive precision instead of silently rounding.
 *  - {@see self::fromFloat()} — last-resort helper for legacy code; prefer
 *    one of the above whenever possible.
 *
 * Output:
 *  - {@see self::amountMinor()} — raw integer for storage / wire formats.
 *  - {@see self::toDecimalString()} — DB-friendly fixed decimal.
 *  - {@see self::format()} — currency-symbol-prefixed display string.
 */
final readonly class Money implements \JsonSerializable
{
    public Currency $currency;
    private int $amountMinor;

    private function __construct(int $amountMinor, Currency $currency)
    {
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
    }

    /**
     * Build from raw minor units (e.g. 299 for $2.99, 1500 for ¥1500).
     *
     * The `$currency` argument is required at every factory: an implicit
     * USD default would silently convert "1500 yen" into "$15.00" when a
     * caller forgot to specify the currency. Pass {@see Currency::usd()}
     * explicitly when USD is intended.
     */
    public static function fromMinorUnits(int $amountMinor, Currency $currency): self
    {
        return new self($amountMinor, $currency);
    }

    /**
     * Build from a decimal string ("2.99", "1.5", "100"). Validates
     * precision against the currency's decimal count and rejects values
     * with too many fractional digits — silent rounding is never
     * acceptable for money.
     */
    public static function fromDecimalString(string $value, Currency $currency): self
    {
        $cleaned = self::cleanDecimalInput($value);

        $decimalsAllowed = $currency->decimals;
        $pattern = $decimalsAllowed === 0
            ? '/^-?\d+$/'
            : sprintf('/^-?\d+(?:\.\d{1,%d})?$/', $decimalsAllowed);

        if (preg_match($pattern, $cleaned) !== 1) {
            throw ValidationException::invalidFormat(
                'amount',
                sprintf('a decimal amount with up to %d decimal places', $decimalsAllowed)
            );
        }

        $isNegative = str_starts_with($cleaned, '-');
        $unsigned = ltrim($cleaned, '-');
        [$major, $minor] = array_pad(explode('.', $unsigned, 2), 2, '');

        $factor = 10 ** $decimalsAllowed;
        $minorPadded = str_pad($minor, $decimalsAllowed, '0');
        $amount = ((int) $major) * $factor + ($decimalsAllowed === 0 ? 0 : (int) $minorPadded);

        if ($isNegative) {
            $amount *= -1;
        }

        return new self($amount, $currency);
    }

    /**
     * Last-resort float factory. Floats lose precision past 2-3 decimal
     * places; prefer fromDecimalString at HTTP / CSV boundaries.
     */
    public static function fromFloat(float $value, Currency $currency): self
    {
        if (!is_finite($value)) {
            throw ValidationException::invalidFormat('amount', 'a finite number');
        }

        $factor = 10 ** $currency->decimals;
        return new self((int) round($value * $factor), $currency);
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function toDecimalString(): string
    {
        if ($this->currency->decimals === 0) {
            return (string) $this->amountMinor;
        }
        $factor = 10 ** $this->currency->decimals;
        $value = $this->amountMinor / $factor;
        return number_format($value, $this->currency->decimals, '.', '');
    }

    public function format(): string
    {
        $absStr = $this->absoluteDecimal();
        $sign = $this->amountMinor < 0 ? '-' : '';
        return $sign . $this->currency->symbol . $absStr;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Wire format for JSON-encoded events / responses. Including the ISO
     * code with the value is the cheapest way to keep multi-currency
     * payloads unambiguous; the previous implementation lost the amount
     * entirely because the integer field was private.
     *
     * @return array{amount_minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency->iso,
            'formatted' => $this->toDecimalString(),
        ];
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->amountMinor === $other->amountMinor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amountMinor > $other->amountMinor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amountMinor < $other->amountMinor;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $multiplier): self
    {
        return new self($this->amountMinor * $multiplier, $this->currency);
    }

    private function absoluteDecimal(): string
    {
        $decimals = $this->currency->decimals;
        $abs = abs($this->amountMinor);
        if ($decimals === 0) {
            return (string) $abs;
        }
        $factor = 10 ** $decimals;
        return number_format($abs / $factor, $decimals, '.', '');
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency->equals($other->currency)) {
            return;
        }
        throw new \InvalidArgumentException(sprintf(
            'Cannot mix currencies: %s vs %s. Convert explicitly first.',
            $this->currency->iso,
            $other->currency->iso
        ));
    }

    private static function cleanDecimalInput(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ValidationException::required('amount');
        }
        $stripped = preg_replace('/^[\$£€¥]\s*/u', '', $trimmed);
        return $stripped === null ? $trimmed : trim($stripped);
    }
}
