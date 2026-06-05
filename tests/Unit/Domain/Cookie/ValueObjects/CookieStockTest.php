<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for CookieStock — the inventory-quantity template VO.
 *
 * Added in round-4 R1 (the VO previously had no dedicated test despite
 * being the pattern every ERP inventory clone copies) together with the
 * MAX_STOCK overflow guard.
 */
final class CookieStockTest extends UnitTestCase
{
    public function test_creates_from_non_negative_int(): void
    {
        $this->assertSame(0, CookieStock::fromInt(0)->value);
        $this->assertSame(45, CookieStock::fromInt(45)->value);
        $this->assertSame(CookieStock::MAX_STOCK, CookieStock::fromInt(CookieStock::MAX_STOCK)->value);
    }

    public function test_rejects_negative_stock(): void
    {
        try {
            CookieStock::fromInt(-1);
            $this->fail('Expected ValidationException for negative stock');
        } catch (ValidationException $e) {
            $this->assertSame(ErrorCodes::COOKIE_VALIDATION_STOCK, $e->getErrorCode());
        }
    }

    public function test_rejects_stock_above_max(): void
    {
        try {
            CookieStock::fromInt(CookieStock::MAX_STOCK + 1);
            $this->fail('Expected ValidationException for stock above MAX_STOCK');
        } catch (ValidationException $e) {
            $this->assertSame(ErrorCodes::COOKIE_VALIDATION_STOCK, $e->getErrorCode());
        }
    }

    public function test_increment_returns_new_instance(): void
    {
        $stock = CookieStock::fromInt(40);
        $increased = $stock->incrementBy(5);

        $this->assertSame(45, $increased->value);
        $this->assertSame(40, $stock->value); // immutable
    }

    public function test_increment_rejects_overflow_past_max(): void
    {
        $stock = CookieStock::fromInt(CookieStock::MAX_STOCK - 1);

        try {
            $stock->incrementBy(2);
            $this->fail('Expected ValidationException for increment past MAX_STOCK');
        } catch (ValidationException $e) {
            $this->assertSame(ErrorCodes::COOKIE_VALIDATION_STOCK, $e->getErrorCode());
        }
    }

    public function test_decrement_returns_new_instance(): void
    {
        $stock = CookieStock::fromInt(10);
        $decreased = $stock->decrementBy(4);

        $this->assertSame(6, $decreased->value);
        $this->assertSame(10, $stock->value); // immutable
    }

    public function test_decrement_below_zero_is_business_rule_violation(): void
    {
        $stock = CookieStock::fromInt(3);

        try {
            $stock->decrementBy(4);
            $this->fail('Expected DomainException for negative resulting stock');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE, $e->getErrorCode());
        }
    }

    public function test_decrement_to_exactly_zero_is_allowed(): void
    {
        $stock = CookieStock::fromInt(4)->decrementBy(4);

        $this->assertSame(0, $stock->value);
        $this->assertTrue($stock->isOutOfStock());
    }

    public function test_increment_and_decrement_reject_non_positive_quantities(): void
    {
        $stock = CookieStock::fromInt(10);

        $this->expectException(ValidationException::class);
        $stock->incrementBy(0);
    }

    public function test_decrement_rejects_negative_quantity(): void
    {
        $stock = CookieStock::fromInt(10);

        $this->expectException(ValidationException::class);
        $stock->decrementBy(-5);
    }

    public function test_is_out_of_stock_only_at_zero(): void
    {
        $this->assertTrue(CookieStock::fromInt(0)->isOutOfStock());
        $this->assertFalse(CookieStock::fromInt(1)->isOutOfStock());
    }
}
