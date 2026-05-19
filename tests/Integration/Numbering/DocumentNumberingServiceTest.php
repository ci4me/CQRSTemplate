<?php

declare(strict_types=1);

namespace Tests\Integration\Numbering;

use App\Infrastructure\Numbering\DocumentNumberingService;
use Config\Database;
use Tests\Support\IntegrationTestCase;

final class DocumentNumberingServiceTest extends IntegrationTestCase
{
    public function test_first_allocation_starts_at_one(): void
    {
        $svc = new DocumentNumberingService();

        $no = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);

        $this->assertSame('invoice', $no->series);
        $this->assertSame('2026', $no->scope);
        $this->assertSame(1, $no->value);
        $this->assertSame('INV-2026-00001', $no->formatted);
    }

    public function test_subsequent_allocations_increment_gaplessly(): void
    {
        $svc = new DocumentNumberingService();

        $first = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);
        $second = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);
        $third = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);

        $this->assertSame(1, $first->value);
        $this->assertSame(2, $second->value);
        $this->assertSame(3, $third->value);
        $this->assertSame('INV-2026-00003', $third->formatted);
    }

    public function test_different_scopes_keep_independent_counters(): void
    {
        $svc = new DocumentNumberingService();

        $a = $svc->allocate('invoice', '2025', 'INV-2025-', '', 5);
        $b = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);
        $c = $svc->allocate('invoice', '2026', 'INV-2026-', '', 5);

        $this->assertSame(1, $a->value, 'fiscal 2025 starts fresh');
        $this->assertSame(1, $b->value, 'fiscal 2026 starts fresh');
        $this->assertSame(2, $c->value, 'fiscal 2026 increments independently');
    }

    public function test_different_series_keep_independent_counters(): void
    {
        $svc = new DocumentNumberingService();

        $svc->allocate('invoice', '', 'INV-', '', 4);
        $svc->allocate('invoice', '', 'INV-', '', 4);
        $po = $svc->allocate('purchase_order', '', 'PO-', '', 4);

        $this->assertSame(1, $po->value, 'purchase_order series is independent');
        $this->assertSame('PO-0001', $po->formatted);
    }

    public function test_peek_returns_null_for_missing_sequence(): void
    {
        $svc = new DocumentNumberingService();

        $this->assertNull($svc->peek('unknown'));
    }

    public function test_peek_returns_current_value_without_bumping(): void
    {
        $svc = new DocumentNumberingService();

        $svc->allocate('invoice', '', 'INV-', '', 4);
        $svc->allocate('invoice', '', 'INV-', '', 4);

        $this->assertSame(2, $svc->peek('invoice'));
        $this->assertSame(2, $svc->peek('invoice'), 'peek is idempotent');
    }

    public function test_empty_series_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DocumentNumberingService())->allocate('');
    }

    public function test_pad_length_validation(): void
    {
        $svc = new DocumentNumberingService();

        try {
            $svc->allocate('x', '', '', '', 0);
            $this->fail('Expected InvalidArgumentException for padLength=0');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            $svc->allocate('x', '', '', '', 21);
            $this->fail('Expected InvalidArgumentException for padLength=21');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_first_call_persists_the_supplied_format(): void
    {
        $svc = new DocumentNumberingService();
        $svc->allocate('invoice', '', 'INV-', 'A', 4);

        $row = Database::connect()->table('document_sequences')->get()->getRowArray();
        $this->assertSame('INV-', $row['prefix']);
        $this->assertSame('A', $row['suffix']);
        $this->assertSame('4', (string) $row['pad_length']);
    }
}
