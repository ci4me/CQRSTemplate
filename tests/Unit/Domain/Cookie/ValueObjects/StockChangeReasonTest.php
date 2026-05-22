<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ValueObjects\StockChangeReason;
use Tests\Support\UnitTestCase;

/**
 * StockChangeReason enum is the typed replacement for the previously
 * stringly-typed `reason` field on CookieStockChangedEvent. These tests
 * pin the case set, backing values, and from()/tryFrom() semantics so a
 * silent rename / removal doesn't leak past CI.
 */
final class StockChangeReasonTest extends UnitTestCase
{
    public function test_has_expected_cases(): void
    {
        $names = array_map(static fn (StockChangeReason $c): string => $c->name, StockChangeReason::cases());

        $this->assertContains('Sale', $names);
        $this->assertContains('Restock', $names);
        $this->assertContains('Return_', $names);
        $this->assertContains('Adjustment', $names);
        $this->assertContains('InitialStock', $names);
        $this->assertCount(5, StockChangeReason::cases(), 'unexpected case count — was a case added/removed?');
    }

    public function test_backing_values_are_screaming_snake_case(): void
    {
        $this->assertSame('SALE', StockChangeReason::Sale->value);
        $this->assertSame('RESTOCK', StockChangeReason::Restock->value);
        $this->assertSame('RETURN', StockChangeReason::Return_->value);
        $this->assertSame('ADJUSTMENT', StockChangeReason::Adjustment->value);
        $this->assertSame('INITIAL_STOCK', StockChangeReason::InitialStock->value);
    }

    public function test_from_round_trips_via_backing_value(): void
    {
        $this->assertSame(StockChangeReason::Sale, StockChangeReason::from('SALE'));
        $this->assertSame(StockChangeReason::Restock, StockChangeReason::from('RESTOCK'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(StockChangeReason::tryFrom('NOT_A_REAL_CASE'));
    }

    public function test_is_a_string_backed_enum(): void
    {
        $reflection = new \ReflectionEnum(StockChangeReason::class);
        $this->assertTrue($reflection->isBacked());
        $backingType = $reflection->getBackingType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $backingType);
        $this->assertSame('string', $backingType->getName());
    }
}
