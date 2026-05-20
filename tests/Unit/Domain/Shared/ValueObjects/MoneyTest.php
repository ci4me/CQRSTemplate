<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Tests\Support\UnitTestCase;

final class MoneyTest extends UnitTestCase
{
    public function test_from_minor_units_round_trips(): void
    {
        $usd = Money::fromMinorUnits(299, Currency::usd());

        $this->assertSame(299, $usd->amountMinor());
        $this->assertSame('2.99', $usd->toDecimalString());
        $this->assertSame('USD', $usd->currency->iso);
    }

    public function test_currency_must_be_passed_explicitly(): void
    {
        // SECURITY/DEFAULTS: the factories no longer accept null currency.
        // Forcing the caller to pass Currency::usd() (or whichever currency
        // applies) prevents silent USD coercion of a JPY/BHD value.
        $m = Money::fromMinorUnits(100, Currency::usd());
        $this->assertSame('USD', $m->currency->iso);
    }

    public function test_from_decimal_string_parses_two_decimal_currency(): void
    {
        $m = Money::fromDecimalString('2.99', Currency::usd());
        $this->assertSame(299, $m->amountMinor());

        $rounded = Money::fromDecimalString('2', Currency::usd());
        $this->assertSame(200, $rounded->amountMinor());
        $this->assertSame('2.00', $rounded->toDecimalString());
    }

    public function test_from_decimal_string_handles_zero_decimal_currency(): void
    {
        $jpy = Money::fromDecimalString('1500', Currency::fromIso('JPY'));
        $this->assertSame(1500, $jpy->amountMinor());
        $this->assertSame('1500', $jpy->toDecimalString());
    }

    public function test_from_decimal_string_handles_three_decimal_currency(): void
    {
        $bhd = Money::fromDecimalString('1.234', Currency::fromIso('BHD'));
        $this->assertSame(1234, $bhd->amountMinor());
        $this->assertSame('1.234', $bhd->toDecimalString());
    }

    public function test_from_decimal_string_rejects_excessive_precision(): void
    {
        $this->expectException(ValidationException::class);
        Money::fromDecimalString('2.999', Currency::usd());
    }

    public function test_from_decimal_string_rejects_decimals_for_zero_decimal_currency(): void
    {
        $this->expectException(ValidationException::class);
        Money::fromDecimalString('1500.5', Currency::fromIso('JPY'));
    }

    public function test_from_decimal_string_strips_leading_currency_symbol(): void
    {
        $m = Money::fromDecimalString('$2.99', Currency::usd());
        $this->assertSame(299, $m->amountMinor());
    }

    public function test_from_decimal_string_rejects_empty(): void
    {
        $this->expectException(ValidationException::class);
        Money::fromDecimalString('   ', Currency::usd());
    }

    public function test_from_float_uses_currency_decimals(): void
    {
        $usd = Money::fromFloat(2.995, Currency::usd());
        $this->assertSame(300, $usd->amountMinor(), 'rounds half-up at 2dp');

        $jpy = Money::fromFloat(1500.0, Currency::fromIso('JPY'));
        $this->assertSame(1500, $jpy->amountMinor());
    }

    public function test_format_uses_currency_symbol(): void
    {
        $usd = Money::fromMinorUnits(299, Currency::usd());
        $this->assertSame('$2.99', $usd->format());

        $eur = Money::fromMinorUnits(1099, Currency::eur());
        $this->assertSame('€10.99', $eur->format());

        $jpy = Money::fromMinorUnits(1500, Currency::fromIso('JPY'));
        $this->assertSame('¥1500', $jpy->format());
    }

    public function test_format_emits_negative_sign_before_symbol(): void
    {
        $m = Money::fromMinorUnits(-150, Currency::usd());
        $this->assertSame('-$1.50', $m->format());
    }

    public function test_equals_requires_same_currency_and_amount(): void
    {
        $a = Money::fromMinorUnits(100, Currency::usd());
        $b = Money::fromMinorUnits(100, Currency::usd());
        $c = Money::fromMinorUnits(100, Currency::eur());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_arithmetic_rejects_mixed_currencies(): void
    {
        $usd = Money::fromMinorUnits(100, Currency::usd());
        $eur = Money::fromMinorUnits(100, Currency::eur());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot mix currencies');

        $usd->add($eur);
    }

    public function test_add_subtract_multiply_preserve_currency(): void
    {
        $a = Money::fromMinorUnits(100, Currency::eur());
        $b = Money::fromMinorUnits(50, Currency::eur());

        $sum = $a->add($b);
        $this->assertSame(150, $sum->amountMinor());
        $this->assertSame('EUR', $sum->currency->iso);

        $diff = $a->subtract($b);
        $this->assertSame(50, $diff->amountMinor());

        $tripled = $a->multiply(3);
        $this->assertSame(300, $tripled->amountMinor());
    }

    public function test_greater_than_less_than_assert_same_currency(): void
    {
        $a = Money::fromMinorUnits(200, Currency::usd());
        $b = Money::fromMinorUnits(100, Currency::usd());

        $this->assertTrue($a->greaterThan($b));
        $this->assertTrue($b->lessThan($a));

        $eur = Money::fromMinorUnits(200, Currency::eur());
        $this->expectException(\InvalidArgumentException::class);
        $a->greaterThan($eur);
    }

    public function test_is_zero_is_negative(): void
    {
        $this->assertTrue(Money::fromMinorUnits(0, Currency::usd())->isZero());
        $this->assertFalse(Money::fromMinorUnits(0, Currency::usd())->isNegative());
        $this->assertTrue(Money::fromMinorUnits(-1, Currency::usd())->isNegative());
    }

    public function test_json_serialise_preserves_amount_and_currency(): void
    {
        $m = Money::fromMinorUnits(1500, Currency::fromIso('JPY'));
        $json = json_encode($m);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertSame(1500, $decoded['amount_minor']);
        $this->assertSame('JPY', $decoded['currency']);
        $this->assertSame('1500', $decoded['formatted']);
    }
}
