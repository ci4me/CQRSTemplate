<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\DocumentNumber;
use Tests\Support\UnitTestCase;

final class DocumentNumberTest extends UnitTestCase
{
    public function test_factory_assigns_all_fields(): void
    {
        $no = DocumentNumber::create('invoice', '2026', 42, 'INV-2026-00042');

        $this->assertSame('invoice', $no->series);
        $this->assertSame('2026', $no->scope);
        $this->assertSame(42, $no->value);
        $this->assertSame('INV-2026-00042', $no->formatted);
    }

    public function test_string_cast_returns_formatted(): void
    {
        $no = DocumentNumber::create('purchase_order', '', 7, 'PO-0007');

        $this->assertSame('PO-0007', (string) $no);
    }

    public function test_empty_series_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentNumber::create('', '2026', 1, 'X-1');
    }

    public function test_zero_or_negative_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentNumber::create('invoice', '2026', 0, 'INV-0000');
    }
}
