<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * Domain-safe view of an attached file (D11).
 *
 * The application layer should accept and return AttachmentRef objects
 * rather than raw `attachments` rows. Carries everything a caller needs to
 * link to the file (id, original name, size, mime) without exposing the
 * storage driver / key — that lives in the infrastructure layer.
 */
final readonly class AttachmentRef
{
    public function __construct(
        public int $id,
        public string $attachableType,
        public string $attachableId,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $checksumSha256,
        public int $uploadedBy
    ) {
    }
}
