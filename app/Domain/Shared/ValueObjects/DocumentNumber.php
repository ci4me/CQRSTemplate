<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * A formatted, human-readable document identifier produced by the
 * DocumentNumberingService (D4).
 *
 * Composed of a prefix, a zero-padded sequence number, and an optional
 * suffix:
 *   {prefix}{padded_value}{suffix}     e.g.  INV-2026-00042
 *
 * The raw integer counter is preserved for ordering and indexing; the
 * formatted name is what humans (and printed documents) see.
 */
final readonly class DocumentNumber
{
    public function __construct(
        public string $series,
        public string $scope,
        public int $value,
        public string $formatted
    ) {
    }

    public function __toString(): string
    {
        return $this->formatted;
    }
}
