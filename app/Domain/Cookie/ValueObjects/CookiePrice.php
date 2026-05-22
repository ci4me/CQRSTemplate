<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Currency;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Cookie sale price (D7).
 *
 * Thin domain-specific wrapper around {@see Money}. Self-validates against
 * COOKIE_VALIDATION_PRICE (must be > 0, must fit a retail catalogue) and
 * pins the currency choice at the boundary. Presentation formatting is
 * delegated to {@see \App\Domain\Cookie\Services\PriceFormatter}.
 */
final readonly class CookiePrice implements \Stringable
{
    private const int MIN_MINOR_UNITS = 1;
    private const int MAX_MINOR_UNITS = 999_999; // 9,999.99 in 2-decimal currencies

    private Money $money;

    private function __construct(Money $money)
    {
        $this->assertPositiveAndInRange($money->amountMinor());
        $this->money = $money;
    }

    /**
     * Create CookiePrice from a decimal string.
     *
     * Floats should stay outside commands and HTTP boundaries. Accept decimal
     * strings instead so invalid precision is rejected instead of silently
     * rounded by the float conversion.
     *
     * @throws ValidationException
     */
    public static function fromString(string $price, ?Currency $currency = null): self
    {
        $trimmed = trim($price);
        if ($trimmed === '') {
            throw ValidationException::required('price', ErrorCodes::COOKIE_VALIDATION_PRICE);
        }

        $money = self::parseMoneyOrFail($trimmed, $currency ?? self::defaultCurrency());

        return new self($money);
    }

    /**
     * @throws ValidationException
     */
    private static function parseMoneyOrFail(string $value, Currency $currency): Money
    {
        try {
            return Money::fromDecimalString($value, $currency);
        } catch (ValidationException) {
            throw ValidationException::invalidFormat(
                'price',
                'a decimal amount with up to 2 decimal places',
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }
    }

    public static function fromMinorUnits(int $minorUnits, ?Currency $currency = null): self
    {
        return new self(Money::fromMinorUnits($minorUnits, $currency ?? self::defaultCurrency()));
    }

    /**
     * Backwards-compatible factory for legacy code/tests.
     *
     * Prefer fromString() at boundaries because float values may already have
     * lost decimal precision before they reach this method.
     */
    public static function fromFloat(float $price, ?Currency $currency = null): self
    {
        return new self(Money::fromFloat($price, $currency ?? self::defaultCurrency()));
    }

    public function getMoney(): Money
    {
        return $this->money;
    }

    public function getCurrency(): Currency
    {
        return $this->money->currency;
    }

    public function getMinorUnits(): int
    {
        return $this->money->amountMinor();
    }

    /**
     * @deprecated Prefer ::getMinorUnits or ::toDecimalString. Float drift
     *             may bite at the boundary; kept for legacy code paths.
     */
    public function getValue(): float
    {
        return $this->money->amountMinor() / (10 ** $this->money->currency->decimals);
    }

    public function toDecimalString(): string
    {
        return $this->money->toDecimalString();
    }

    public function toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Format with the underlying currency's symbol.
     *
     * @deprecated Use {@see \App\Domain\Cookie\Services\PriceFormatter::format()}.
     */
    public function format(?string $currencySymbol = null): string
    {
        if ($currencySymbol === null) {
            return $this->money->format();
        }
        return $currencySymbol . $this->toDecimalString();
    }

    public function equals(self $other): bool
    {
        return $this->money->equals($other->money);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->money->greaterThan($other->money);
    }

    public function isLessThan(self $other): bool
    {
        return $this->money->lessThan($other->money);
    }

    public function add(self $other): self
    {
        return new self($this->money->add($other->money));
    }

    public function subtract(self $other): self
    {
        return new self($this->money->subtract($other->money));
    }

    /**
     * @throws ValidationException
     */
    public function multiplyBy(int $quantity): self
    {
        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }
        return new self($this->money->multiply($quantity));
    }

    /**
     * @throws ValidationException
     */
    public function applyDiscount(float $discountPercent): self
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
        $discountedMinor = (int) round($this->money->amountMinor() * (100 - $discountPercent) / 100);
        return new self(Money::fromMinorUnits($discountedMinor, $this->money->currency));
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * @throws ValidationException
     */
    private function assertPositiveAndInRange(int $minorUnits): void
    {
        if ($minorUnits < self::MIN_MINOR_UNITS) {
            throw ValidationException::tooSmall(
                'price',
                self::MIN_MINOR_UNITS / 100,
                $minorUnits / 100,
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }
        if ($minorUnits > self::MAX_MINOR_UNITS) {
            throw ValidationException::outOfRange(
                'price',
                self::MIN_MINOR_UNITS / 100,
                self::MAX_MINOR_UNITS / 100,
                $minorUnits / 100,
                ErrorCodes::COOKIE_VALIDATION_PRICE
            );
        }
    }

    /**
     * Cookie domain default currency. Becomes configurable via
     * SettingsService when multi-currency catalogues land.
     */
    private static function defaultCurrency(): Currency
    {
        return Currency::default();
    }
}
