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
    /**
     * __construct.
     *
     * @param int         $id
     * @param string      $attachableType
     * @param string      $attachableId
     * @param string      $originalName
     * @param string      $mimeType
     * @param int         $sizeBytes
     * @param string|null $checksumSha256
     * @param int         $uploadedBy
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function __construct(
        public int $id,
        public string $attachableType,
        public string $attachableId,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $checksumSha256,
        public int $uploadedBy
    ) {
        $this->assertValid();
    }

    /**
     * Build a reference for a freshly persisted attachment. The arguments
     * are validated (positive id, non-empty type/name/mime, non-negative
     * size) so the rest of the domain doesn't have to worry about
     * malformed values smuggled in via direct construction.
     *
     * @param int         $id
     * @param string      $attachableType
     * @param string      $attachableId
     * @param string      $originalName
     * @param string      $mimeType
     * @param int         $sizeBytes
     * @param string|null $checksumSha256
     * @param int         $uploadedBy
     * @return self
     */
    public static function create(
        int $id,
        string $attachableType,
        string $attachableId,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        ?string $checksumSha256,
        int $uploadedBy
    ): self {
        return new self(
            $id,
            $attachableType,
            $attachableId,
            $originalName,
            $mimeType,
            $sizeBytes,
            $checksumSha256,
            $uploadedBy
        );
    }

    /**
     * Rebuild from a persisted row. Same invariants — a database column
     * could have been edited by hand.
     *
     * @param int         $id
     * @param string      $attachableType
     * @param string      $attachableId
     * @param string      $originalName
     * @param string      $mimeType
     * @param int         $sizeBytes
     * @param string|null $checksumSha256
     * @param int         $uploadedBy
     * @return self
     */
    public static function reconstitute(
        int $id,
        string $attachableType,
        string $attachableId,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        ?string $checksumSha256,
        int $uploadedBy
    ): self {
        return new self(
            $id,
            $attachableType,
            $attachableId,
            $originalName,
            $mimeType,
            $sizeBytes,
            $checksumSha256,
            $uploadedBy
        );
    }

    /**
     * assertValid.
     *
     * @return void
     * @throws \InvalidArgumentException
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function assertValid(): void
    {
        if ($this->id <= 0) {
            throw new \InvalidArgumentException('AttachmentRef: id must be > 0.');
        }
        if ($this->attachableType === '') {
            throw new \InvalidArgumentException('AttachmentRef: attachableType must not be empty.');
        }
        if ($this->originalName === '') {
            throw new \InvalidArgumentException('AttachmentRef: originalName must not be empty.');
        }
        if ($this->mimeType === '') {
            throw new \InvalidArgumentException('AttachmentRef: mimeType must not be empty.');
        }
        if ($this->sizeBytes < 0) {
            throw new \InvalidArgumentException('AttachmentRef: sizeBytes must be >= 0.');
        }
        if ($this->uploadedBy < 0) {
            throw new \InvalidArgumentException('AttachmentRef: uploadedBy must be >= 0.');
        }
    }
}
