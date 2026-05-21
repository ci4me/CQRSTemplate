<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * ISO-4217-aligned currency value object.
 *
 * Every monetary amount in the system carries a Currency. Currencies are
 * created via {@see self::fromIso} which validates that the code matches
 * the 3-letter uppercase ISO-4217 shape (e.g. USD, EUR, BRL, JPY).
 *
 * `decimals` is the standard minor-unit precision for the currency. Most
 * currencies use 2 (cents); JPY/KRW use 0; BHD/IQD use 3. The default
 * registry below covers the common cases; unknown codes default to 2 and
 * can be overridden via the second constructor arg.
 */
final readonly class Currency
{
    /**
     * @var array<string, int> ISO-4217 minor-unit overrides for currencies
     *                          that do not use 2 decimals.
     */
    private const array MINOR_UNIT_OVERRIDES = [
        'JPY' => 0,
        'KRW' => 0,
        'VND' => 0,
        'BHD' => 3,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'OMR' => 3,
        'TND' => 3,
    ];

    /**
     * __construct.
     */
    private function __construct(
        public string $iso,
        public int $decimals,
        public string $symbol
    ) {
    }

    /**
     * fromIso.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromIso(string $iso, ?string $symbol = null): self
    {
        $iso = strtoupper(trim($iso));

        if (preg_match('/^[A-Z]{3}$/', $iso) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Currency code must be 3 uppercase letters (ISO-4217). Got: %s', $iso)
            );
        }

        return new self(
            iso: $iso,
            decimals: self::MINOR_UNIT_OVERRIDES[$iso] ?? 2,
            symbol: $symbol ?? self::defaultSymbolFor($iso)
        );
    }

    /**
     * usd.
     */
    public static function usd(): self
    {
        return self::fromIso('USD', '$');
    }

    /**
     * eur.
     */
    public static function eur(): self
    {
        return self::fromIso('EUR', '€');
    }

    /**
     * brl.
     */
    public static function brl(): self
    {
        return self::fromIso('BRL', 'R$');
    }

    /**
     * Deployment-wide default currency.
     *
     * Reads the `DEFAULT_CURRENCY` env var (canonical 3-letter ISO code,
     * e.g. `USD`, `EUR`, `BRL`). Falls back to `USD` so existing tests
     * and single-currency deploys aren't broken. Use this anywhere a
     * "default currency" decision is being made (e.g. CookiePrice when
     * the request doesn't specify one) so the choice has a single
     * source of truth.
     */
    public static function default(): self
    {
        $env = getenv('DEFAULT_CURRENCY');
        if (!is_string($env) || $env === '') {
            return self::usd();
        }
        $iso = strtoupper(trim($env));
        if (preg_match('/^[A-Z]{3}$/', $iso) !== 1) {
            return self::usd();
        }
        return self::fromIso($iso);
    }

    /**
     * equals.
     */
    public function equals(self $other): bool
    {
        return $this->iso === $other->iso;
    }

    /**
     * defaultSymbolFor.
     */
    private static function defaultSymbolFor(string $iso): string
    {
        return match ($iso) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'BRL' => 'R$',
            'CAD' => 'C$',
            'AUD' => 'A$',
            default => $iso,
        };
    }
}
