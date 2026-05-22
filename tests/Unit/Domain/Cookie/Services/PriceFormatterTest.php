<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Services;

use App\Domain\Cookie\Services\PriceFormatter;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\ValueObjects\Currency;
use Tests\Support\UnitTestCase;

/**
 * Direct unit coverage for the {@see PriceFormatter} service.
 *
 * Phase-4 split: presentation formatting moved out of {@see CookiePrice}
 * into a stateless service that takes a CookiePrice + optional override
 * symbol. The two-branch shape (`$currencySymbol === null` -> delegate
 * to Money::format, otherwise prepend the symbol) was previously only
 * covered transitively via {@see \Tests\Unit\Domain\Cookie\ValueObjects\CookiePriceTest}.
 *
 * Per audit slice 07/F3 the override symbol is **prefix-only**: it is
 * prepended to the price's decimal string verbatim. We pin that
 * behaviour here so a future refactor that, say, locale-flips the
 * thousands separator does not silently change the API.
 *
 * Closes slice 12/F2 + missing-2.
 */
final class PriceFormatterTest extends UnitTestCase
{
    public function test_format_without_override_uses_currency_symbol_from_money(): void
    {
        $price = CookiePrice::fromString('2.99', Currency::usd());

        $this->assertSame('$2.99', PriceFormatter::format($price));
    }

    public function test_format_without_override_uses_brl_symbol(): void
    {
        $price = CookiePrice::fromString('10.50', Currency::brl());

        $this->assertSame('R$10.50', PriceFormatter::format($price));
    }

    public function test_format_without_override_uses_eur_symbol(): void
    {
        $price = CookiePrice::fromString('3.45', Currency::eur());

        $this->assertSame('€3.45', PriceFormatter::format($price));
    }

    public function test_format_with_explicit_symbol_overrides_default(): void
    {
        $price = CookiePrice::fromString('2.99', Currency::usd());

        $this->assertSame('R$2.99', PriceFormatter::format($price, 'R$'));
    }

    public function test_format_with_empty_string_symbol_is_pure_decimal(): void
    {
        // Edge case: empty override = strip the symbol entirely (the
        // service does a literal prepend, no fallback).
        $price = CookiePrice::fromString('2.99', Currency::usd());

        $this->assertSame('2.99', PriceFormatter::format($price, ''));
    }

    public function test_format_with_override_is_prefix_only_per_audit_slice_07(): void
    {
        // The override is concatenated as a prefix — it does NOT replace
        // the currency's decimal precision or perform any locale-aware
        // formatting. See audit slice 07/F3 + the PriceFormatter docblock.
        $price = CookiePrice::fromString('1234.56', Currency::usd());

        $this->assertSame('USD 1234.56', PriceFormatter::format($price, 'USD '));
    }

    public function test_format_zero_price(): void
    {
        $price = CookiePrice::fromString('0.01', Currency::usd());

        $this->assertSame('$0.01', PriceFormatter::format($price));
        $this->assertSame('R$0.01', PriceFormatter::format($price, 'R$'));
    }
}
