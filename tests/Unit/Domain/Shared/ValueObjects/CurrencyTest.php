<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Currency;
use Tests\Support\UnitTestCase;

final class CurrencyTest extends UnitTestCase
{
    public function test_usd_factory_returns_dollar_currency(): void
    {
        $c = Currency::usd();

        $this->assertSame('USD', $c->iso);
        $this->assertSame(2, $c->decimals);
        $this->assertSame('$', $c->symbol);
    }

    public function test_from_iso_uppercases_input(): void
    {
        $c = Currency::fromIso('eur');

        $this->assertSame('EUR', $c->iso);
        $this->assertSame('€', $c->symbol);
    }

    public function test_jpy_has_zero_decimals(): void
    {
        $c = Currency::fromIso('JPY');
        $this->assertSame(0, $c->decimals);
    }

    public function test_bhd_has_three_decimals(): void
    {
        $c = Currency::fromIso('BHD');
        $this->assertSame(3, $c->decimals);
    }

    public function test_unknown_currency_defaults_to_two_decimals_and_iso_as_symbol(): void
    {
        $c = Currency::fromIso('ZZZ');
        $this->assertSame('ZZZ', $c->iso);
        $this->assertSame(2, $c->decimals);
        $this->assertSame('ZZZ', $c->symbol);
    }

    public function test_invalid_iso_codes_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Currency::fromIso('US');
    }

    public function test_invalid_with_digits_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Currency::fromIso('US1');
    }

    public function test_equals_compares_by_iso(): void
    {
        $a = Currency::usd();
        $b = Currency::fromIso('USD');
        $c = Currency::eur();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_custom_symbol_can_be_supplied(): void
    {
        $c = Currency::fromIso('USD', 'US$');
        $this->assertSame('US$', $c->symbol);
    }
}
