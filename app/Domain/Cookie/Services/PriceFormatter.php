<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Services;

use App\Domain\Cookie\ValueObjects\CookiePrice;

/**
 * Formats a {@see CookiePrice} for human-readable display.
 *
 * Phase 4 split: extracted from CookiePrice to keep the value object
 * focused on monetary invariants. Presentation logic (currency-symbol
 * override, decimal formatting) is not part of the value-object contract;
 * it's a small stateless service that takes a CookiePrice and produces
 * a string.
 *
 * Usage:
 *   PriceFormatter::format($price);              // "$2.99" (uses currency)
 *   PriceFormatter::format($price, 'R$');        // "R$2.99" (override)
 */
final class PriceFormatter
{
    /**
     * Format a CookiePrice as a localised display string.
     *
     * When `$currencySymbol` is null the formatter delegates to the
     * underlying Money's own format() (which uses the currency's symbol).
     * When supplied, the symbol is prepended to the decimal string
     * verbatim - useful for locale-specific overrides at the boundary.
     */
    public static function format(CookiePrice $price, ?string $currencySymbol = null): string
    {
        if ($currencySymbol === null) {
            return $price->getMoney()->format();
        }
        return $currencySymbol . $price->toDecimalString();
    }
}
