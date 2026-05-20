<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for CookiePrice Value Object.
 */
final class CookiePriceTest extends UnitTestCase
{
    public function test_can_create_from_decimal_string(): void
    {
        $price = CookiePrice::fromString('4.50');

        $this->assertInstanceOf(CookiePrice::class, $price);
        $this->assertSame(450, $price->getMinorUnits());
        $this->assertEquals(4.50, $price->getValue());
        $this->assertSame('4.50', $price->toDecimalString());
    }

    public function test_can_create_from_integer_minor_units(): void
    {
        $price = CookiePrice::fromMinorUnits(299);

        $this->assertSame(299, $price->getMinorUnits());
        $this->assertSame('2.99', $price->toString());
    }

    public function test_from_float_is_kept_for_legacy_code(): void
    {
        $price = CookiePrice::fromFloat(2.995);

        $this->assertSame(300, $price->getMinorUnits());
        $this->assertSame('3.00', $price->toDecimalString());
    }

    public function test_accepts_minimum_price(): void
    {
        $price = CookiePrice::fromString('0.01');

        $this->assertSame(1, $price->getMinorUnits());
        $this->assertSame('$0.01', $price->format());
    }

    public function test_accepts_large_price(): void
    {
        $price = CookiePrice::fromString('9999.99');

        $this->assertSame(999999, $price->getMinorUnits());
    }

    public function test_accepts_integer_price(): void
    {
        $price = CookiePrice::fromString('5');

        $this->assertSame(500, $price->getMinorUnits());
        $this->assertSame('5.00', $price->toDecimalString());
    }

    #[DataProvider('invalidPriceProvider')]
    public function test_throws_exception_for_invalid_prices(string $price, string $expectedMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedMessage);

        CookiePrice::fromString($price);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPriceProvider(): array
    {
        return [
            'empty' => ['', 'is required'],
            'whitespace' => ['  ', 'is required'],
            'zero' => ['0', 'must be at least'],
            'negative' => ['-5.99', 'must be at least'],
            'letters' => ['abc', 'invalid format'],
            'mixed alphanumeric' => ['12abc', 'invalid format'],
            'too many decimals' => ['2.999', 'invalid format'],
            'missing major units' => ['.99', 'invalid format'],
            'trailing decimal point' => ['5.', 'invalid format'],
        ];
    }

    public function test_throws_exception_for_zero_float(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be at least');

        CookiePrice::fromFloat(0.00);
    }

    public function test_format_returns_currency_string(): void
    {
        $price = CookiePrice::fromString('2.99');

        $this->assertSame('$2.99', $price->format());
        $this->assertSame('EUR 2.99', $price->format('EUR '));
    }

    public function test_string_parsing_with_currency_symbol(): void
    {
        $price = CookiePrice::fromString('$5.99');

        $this->assertSame(599, $price->getMinorUnits());
    }

    public function test_string_parsing_with_whitespace(): void
    {
        $price = CookiePrice::fromString('  3.50  ');

        $this->assertSame('3.50', $price->toDecimalString());
    }

    public function test_comparison_methods_use_minor_units(): void
    {
        $price1 = CookiePrice::fromString('3.00');
        $price2 = CookiePrice::fromString('2.00');
        $price3 = CookiePrice::fromMinorUnits(300);

        $this->assertTrue($price1->isGreaterThan($price2));
        $this->assertTrue($price2->isLessThan($price1));
        $this->assertTrue($price1->equals($price3));
        $this->assertFalse($price1->equals($price2));
    }

    public function test_add_returns_new_price(): void
    {
        $price1 = CookiePrice::fromString('2.50');
        $price2 = CookiePrice::fromString('1.50');

        $result = $price1->add($price2);

        $this->assertSame('4.00', $result->toDecimalString());
        $this->assertSame('2.50', $price1->toDecimalString());
    }

    public function test_subtract_returns_new_price(): void
    {
        $price1 = CookiePrice::fromString('5.00');
        $price2 = CookiePrice::fromString('2.00');

        $result = $price1->subtract($price2);

        $this->assertSame('3.00', $result->toDecimalString());
    }

    public function test_subtract_throws_exception_if_result_would_be_negative(): void
    {
        $price1 = CookiePrice::fromString('1.00');
        $price2 = CookiePrice::fromString('2.00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be at least');

        $price1->subtract($price2);
    }

    public function test_multiply_by_quantity(): void
    {
        $price = CookiePrice::fromString('2.50');

        $result = $price->multiplyBy(3);

        $this->assertSame('7.50', $result->toDecimalString());
    }

    public function test_multiply_throws_exception_for_zero_quantity(): void
    {
        $price = CookiePrice::fromString('2.50');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be at least 1');

        $price->multiplyBy(0);
    }

    public function test_apply_discount(): void
    {
        $price = CookiePrice::fromString('10.00');

        $this->assertSame('8.50', $price->applyDiscount(15)->toDecimalString());
    }

    public function test_apply_discount_rejects_invalid_percentage(): void
    {
        $price = CookiePrice::fromString('10.00');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be between');

        $price->applyDiscount(125);
    }

    public function test_to_string_returns_decimal_string(): void
    {
        $price = CookiePrice::fromString('4.99');

        $this->assertSame('4.99', (string) $price);
    }

    public function test_default_currency_is_usd_and_format_uses_dollar_sign(): void
    {
        $price = CookiePrice::fromString('4.99');

        $this->assertSame('USD', $price->getCurrency()->iso);
        $this->assertSame('$4.99', $price->format());
    }

    public function test_explicit_currency_changes_format_symbol(): void
    {
        $price = CookiePrice::fromString('4.99', Currency::eur());

        $this->assertSame('EUR', $price->getCurrency()->iso);
        $this->assertSame('€4.99', $price->format());
        $this->assertSame(499, $price->getMinorUnits());
    }

    public function test_format_with_explicit_symbol_overrides_default(): void
    {
        $price = CookiePrice::fromString('4.99');

        $this->assertSame('R$4.99', $price->format('R$'));
    }

    public function test_arithmetic_across_currencies_is_rejected(): void
    {
        $usd = CookiePrice::fromString('1.00', Currency::usd());
        $eur = CookiePrice::fromString('1.00', Currency::eur());

        $this->expectException(\InvalidArgumentException::class);
        $usd->add($eur);
    }

    public function test_get_money_exposes_underlying_value_object(): void
    {
        $price = CookiePrice::fromString('1.00');
        $money = $price->getMoney();

        $this->assertSame(100, $money->amountMinor());
        $this->assertSame('USD', $money->currency->iso);
    }
}
