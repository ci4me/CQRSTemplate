<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\AttachmentRef;
use InvalidArgumentException;
use Tests\Support\UnitTestCase;

final class AttachmentRefTest extends UnitTestCase
{
    public function test_create_exposes_all_fields(): void
    {
        $ref = AttachmentRef::create(
            id: 42,
            attachableType: 'Invoice',
            attachableId: 'INV-2026-00001',
            originalName: 'receipt.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 2048,
            checksumSha256: hash('sha256', 'sample'),
            uploadedBy: 7,
        );

        $this->assertSame(42, $ref->id);
        $this->assertSame('Invoice', $ref->attachableType);
        $this->assertSame('INV-2026-00001', $ref->attachableId);
        $this->assertSame('receipt.pdf', $ref->originalName);
        $this->assertSame('application/pdf', $ref->mimeType);
        $this->assertSame(2048, $ref->sizeBytes);
        $this->assertSame(7, $ref->uploadedBy);
    }

    public function test_reconstitute_accepts_null_checksum(): void
    {
        $ref = AttachmentRef::reconstitute(1, 'X', 'y', 'n.txt', 'text/plain', 0, null, 0);
        $this->assertNull($ref->checksumSha256);
    }

    public function test_zero_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must be > 0');
        AttachmentRef::create(0, 'X', 'y', 'n', 'm/m', 1, null, 1);
    }

    public function test_empty_attachable_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attachableType must not be empty');
        AttachmentRef::create(1, '', 'y', 'n', 'm/m', 1, null, 1);
    }

    public function test_empty_original_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('originalName must not be empty');
        AttachmentRef::create(1, 'X', 'y', '', 'm/m', 1, null, 1);
    }

    public function test_empty_mime_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mimeType must not be empty');
        AttachmentRef::create(1, 'X', 'y', 'n', '', 1, null, 1);
    }

    public function test_negative_size_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sizeBytes must be >= 0');
        AttachmentRef::create(1, 'X', 'y', 'n', 'm/m', -1, null, 1);
    }

    public function test_negative_uploaded_by_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('uploadedBy must be >= 0');
        AttachmentRef::create(1, 'X', 'y', 'n', 'm/m', 1, null, -1);
    }
}
