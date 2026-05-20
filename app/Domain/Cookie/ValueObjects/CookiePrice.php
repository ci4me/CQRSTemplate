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
 * Thin domain-specific wrapper around {@see Money}. Encodes the Cookie
 * domain's business rules around price (must be > 0, must fit a typical
 * retail catalogue) while delegating amount / currency mechanics to the
 * shared Money value object.
 *
 * Why a wrapper instead of using Money directly:
 *  - Self-validates against COOKIE_VALIDATION_PRICE so callers get the
 *    right domain error code.
 *  - Keeps the type signature explicit: a method that takes a CookiePrice
 *    can never receive a Money for shipping fees.
 *  - Pins the currency choice for cookies at the boundary (currently USD;
 *    becomes configurable via SettingsService when multi-currency lands).
 *
 * Usage:
 *   $price = CookiePrice::fromString('2.99');     // assumes USD
 *   $price->toDecimalString();                    // "2.99"
 *   $price->getMinorUnits();                       // 299
 *   $price->getMoney()->currency->iso;             // "USD"
 *   $price->format();                              // "$2.99"
 */
final readonly class CookiePrice
{
    private const int MIN_MINOR_UNITS = 1;
    private const int MAX_MINOR_UNITS = 999_999; // 9,999.99 in 2-decimal currencies

    /** @var Money */
    private Money $money;

    /**
     * __construct.
     *
     * @param Money $money
     * @todo Auto-generated docblock — review and replace this description.
     */
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
     * @param string        $price
     * @param Currency|null $currency
     * @return self
     * @throws ValidationException
     */
    public static function fromString(string $price, ?Currency $currency = null): self
    {
        $trimmed = trim($price);
        if ($trimmed === '') {
            throw ValidationException::required('price', ErrorCodes::COOKIE_VALIDATION_PRICE);
        }

        // Parse first (a format failure is mapped to the cookie-specific code).
        // The constructor enforces the cookie-specific range and emits the
        // existing tooSmall/outOfRange messages — those are not caught here.
        $money = self::parseMoneyOrFail($trimmed, $currency ?? self::defaultCurrency());

        return new self($money);
    }

    /**
     * parseMoneyOrFail.
     *
     * @param string   $value
     * @param Currency $currency
     * @return Money
     * @throws ValidationException
     * @todo Auto-generated docblock — review and replace this description.
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

    /**
     * fromMinorUnits.
     *
     * @param int           $minorUnits
     * @param Currency|null $currency
     * @return self
     * @todo Auto-generated docblock — review and replace this description.
     */
    public static function fromMinorUnits(int $minorUnits, ?Currency $currency = null): self
    {
        return new self(Money::fromMinorUnits($minorUnits, $currency ?? self::defaultCurrency()));
    }

    /**
     * Backwards-compatible factory for legacy code/tests.
     *
     * Prefer fromString() at boundaries because float values may already have
     * lost decimal precision before they reach this method.
     *
     * @param float         $price
     * @param Currency|null $currency
     * @return self
     */
    public static function fromFloat(float $price, ?Currency $currency = null): self
    {
        return new self(Money::fromFloat($price, $currency ?? self::defaultCurrency()));
    }

    /**
     * getMoney.
     *
     * @return Money
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getMoney(): Money
    {
        return $this->money;
    }

    /**
     * getCurrency.
     *
     * @return Currency
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getCurrency(): Currency
    {
        return $this->money->currency;
    }

    /**
     * getMinorUnits.
     *
     * @return int
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function getMinorUnits(): int
    {
        return $this->money->amountMinor();
    }

    /**
     * @deprecated Prefer ::getMinorUnits or ::toDecimalString. Float drift
     *             may bite at the boundary; kept for legacy code paths.
     * @return float
     */
    public function getValue(): float
    {
        return $this->money->amountMinor() / (10 ** $this->money->currency->decimals);
    }

    /**
     * toDecimalString.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function toDecimalString(): string
    {
        return $this->money->toDecimalString();
    }

    /**
     * toString.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Format with the underlying currency's symbol. The legacy `$currency`
     * parameter is preserved for callers that want to override the symbol
     * (e.g. for a localised display) without changing the underlying
     * monetary value.
     *
     * @param string|null $currencySymbol
     * @return string
     */
    public function format(?string $currencySymbol = null): string
    {
        if ($currencySymbol === null) {
            return $this->money->format();
        }
        return $currencySymbol . $this->toDecimalString();
    }

    /**
     * equals.
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function equals(self $other): bool
    {
        return $this->money->equals($other->money);
    }

    /**
     * greaterThan.
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function greaterThan(self $other): bool
    {
        return $this->money->greaterThan($other->money);
    }

    /**
     * isGreaterThan.
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isGreaterThan(self $other): bool
    {
        return $this->greaterThan($other);
    }

    /**
     * lessThan.
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function lessThan(self $other): bool
    {
        return $this->money->lessThan($other->money);
    }

    /**
     * isLessThan.
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isLessThan(self $other): bool
    {
        return $this->lessThan($other);
    }

    /**
     * add.
     *
     * @param self $other
     * @return self
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function add(self $other): self
    {
        return new self($this->money->add($other->money));
    }

    /**
     * subtract.
     *
     * @param self $other
     * @return self
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function subtract(self $other): self
    {
        return new self($this->money->subtract($other->money));
    }

    /**
     * multiplyBy.
     *
     * @param int $quantity
     * @return self
     * @throws ValidationException
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function multiplyBy(int $quantity): self
    {
        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }
        return new self($this->money->multiply($quantity));
    }

    /**
     * applyDiscount.
     *
     * @param float $discountPercent
     * @return self
     * @throws ValidationException
     * @todo Auto-generated docblock — review and replace this description.
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

    /**
     * __toString.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * assertPositiveAndInRange.
     *
     * @param int $minorUnits
     * @return void
     * @throws ValidationException
     * @todo Auto-generated docblock — review and replace this description.
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
     *
     * @return Currency
     */
    private static function defaultCurrency(): Currency
    {
        // Read from the deployment-wide source of truth so a multi-
        // currency rollout doesn't have to fork CookiePrice. Falls back
        // to USD when DEFAULT_CURRENCY env isn't set, matching the
        // pre-existing single-currency behaviour.
        return Currency::default();
    }
}
