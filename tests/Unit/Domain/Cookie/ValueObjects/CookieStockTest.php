<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * Direct unit coverage for the {@see CookieStock} value object.
 *
 * Phase-4 split: CookieStock used to live inline on the Cookie entity, so
 * it was only exercised transitively through {@see \Tests\Unit\Domain\Cookie\Entities\CookieTest}.
 * Cloning the Cookie template into a new domain meant cloning a VO that
 * had no direct test of its own — this file closes that gap and pins the
 * promise made in the CookieStock docblock ("AI agents and humans can
 * reason about [it] in isolation").
 *
 * Branches covered:
 *  - fromInt: negative rejected, zero accepted, positive accepted.
 *  - decrementBy: non-positive quantity rejected, would-go-below-zero
 *    rejected, happy path returns new instance.
 *  - incrementBy: non-positive quantity rejected, happy path returns new
 *    instance.
 *  - isOutOfStock: zero is out, non-zero is in.
 *  - Immutability: every mutation returns a new instance, original is
 *    unchanged.
 *
 * Closes slice 12/F2 + missing-1.
 *
 * Note on error-code assertions: ValidationException and DomainException
 * store the domain code in a dedicated `errorCode` field (queried via
 * `getErrorCode()`), not in PHP's native `$code`. We catch + assert
 * explicitly rather than using `expectExceptionCode` so the audit's
 * "missing-error-code at CookieStock:89" finding is visible.
 */
final class CookieStockTest extends UnitTestCase
{
    public function test_from_int_accepts_zero(): void
    {
        $stock = CookieStock::fromInt(0);

        $this->assertSame(0, $stock->value);
        $this->assertTrue($stock->isOutOfStock());
    }

    public function test_from_int_accepts_positive(): void
    {
        $stock = CookieStock::fromInt(42);

        $this->assertSame(42, $stock->value);
        $this->assertFalse($stock->isOutOfStock());
    }

    public function test_from_int_accepts_large_value(): void
    {
        $stock = CookieStock::fromInt(PHP_INT_MAX);

        $this->assertSame(PHP_INT_MAX, $stock->value);
    }

    public function test_from_int_rejects_negative(): void
    {
        try {
            CookieStock::fromInt(-1);
            $this->fail('Expected ValidationException for negative stock');
        } catch (ValidationException $e) {
            $this->assertSame(ErrorCodes::COOKIE_VALIDATION_STOCK, $e->getErrorCode());
        }
    }

    public function test_from_int_rejects_negative_with_large_magnitude(): void
    {
        try {
            CookieStock::fromInt(-1000);
            $this->fail('Expected ValidationException for large negative stock');
        } catch (ValidationException $e) {
            $this->assertSame(ErrorCodes::COOKIE_VALIDATION_STOCK, $e->getErrorCode());
        }
    }

    public function test_decrement_by_returns_new_instance_with_reduced_value(): void
    {
        $stock = CookieStock::fromInt(50);

        $next = $stock->decrementBy(10);

        $this->assertSame(40, $next->value);
        // Original is unchanged — value-object immutability.
        $this->assertSame(50, $stock->value);
        $this->assertNotSame($stock, $next);
    }

    public function test_decrement_by_to_exact_zero_is_allowed(): void
    {
        $stock = CookieStock::fromInt(5);

        $next = $stock->decrementBy(5);

        $this->assertSame(0, $next->value);
        $this->assertTrue($next->isOutOfStock());
    }

    public function test_decrement_by_rejects_zero_quantity(): void
    {
        $this->expectException(ValidationException::class);

        CookieStock::fromInt(10)->decrementBy(0);
    }

    public function test_decrement_by_rejects_negative_quantity(): void
    {
        $this->expectException(ValidationException::class);

        CookieStock::fromInt(10)->decrementBy(-1);
    }

    public function test_decrement_by_rejects_quantity_greater_than_value(): void
    {
        try {
            CookieStock::fromInt(5)->decrementBy(6);
            $this->fail('Expected DomainException when decrement would go negative');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE, $e->getErrorCode());
        }
    }

    public function test_decrement_by_rejects_quantity_greater_than_value_at_boundary(): void
    {
        try {
            CookieStock::fromInt(0)->decrementBy(1);
            $this->fail('Expected DomainException when decrementing zero stock');
        } catch (DomainException $e) {
            $this->assertSame(ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE, $e->getErrorCode());
        }
    }

    public function test_increment_by_returns_new_instance_with_increased_value(): void
    {
        $stock = CookieStock::fromInt(10);

        $next = $stock->incrementBy(7);

        $this->assertSame(17, $next->value);
        // Original is unchanged.
        $this->assertSame(10, $stock->value);
        $this->assertNotSame($stock, $next);
    }

    public function test_increment_by_from_zero_resurrects_stock(): void
    {
        $stock = CookieStock::fromInt(0);
        $this->assertTrue($stock->isOutOfStock());

        $next = $stock->incrementBy(3);

        $this->assertSame(3, $next->value);
        $this->assertFalse($next->isOutOfStock());
    }

    public function test_increment_by_rejects_zero_quantity(): void
    {
        $this->expectException(ValidationException::class);

        CookieStock::fromInt(10)->incrementBy(0);
    }

    public function test_increment_by_rejects_negative_quantity(): void
    {
        $this->expectException(ValidationException::class);

        CookieStock::fromInt(10)->incrementBy(-5);
    }

    public function test_is_out_of_stock_is_true_only_at_zero(): void
    {
        $this->assertTrue(CookieStock::fromInt(0)->isOutOfStock());
        $this->assertFalse(CookieStock::fromInt(1)->isOutOfStock());
        $this->assertFalse(CookieStock::fromInt(100)->isOutOfStock());
    }
}
