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
    /**
     * __construct.
     *
     * @param string $series
     * @param string $scope
     * @param int    $value
     * @param string $formatted
     */
    private function __construct(
        public string $series,
        public string $scope,
        public int $value,
        public string $formatted
    ) {
        $this->assertValid();
    }

    /**
     * Factory used by {@see \App\Infrastructure\Numbering\DocumentNumberingService}
     * after it allocates a fresh sequence row. The arguments are validated
     * to catch corrupted/badly configured rows before they reach a document
     * (an invoice with `series=""` would silently slip past a public ctor).
     *
     * @param string $series
     * @param string $scope
     * @param int    $value
     * @param string $formatted
     * @return self
     */
    public static function create(string $series, string $scope, int $value, string $formatted): self
    {
        return new self($series, $scope, $value, $formatted);
    }

    /**
     * Rebuild from persistence. Same validation rules — the column may
     * have been edited by hand or by a buggy migration.
     *
     * @param string $series
     * @param string $scope
     * @param int    $value
     * @param string $formatted
     * @return self
     */
    public static function reconstitute(string $series, string $scope, int $value, string $formatted): self
    {
        return new self($series, $scope, $value, $formatted);
    }

    /**
     * __toString.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->formatted;
    }

    /**
     * assertValid.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertValid(): void
    {
        if ($this->series === '') {
            throw new \InvalidArgumentException('DocumentNumber: series must not be empty.');
        }
        if ($this->value < 1) {
            throw new \InvalidArgumentException('DocumentNumber: value must be >= 1.');
        }
        if ($this->formatted === '') {
            throw new \InvalidArgumentException('DocumentNumber: formatted must not be empty.');
        }
    }
}
