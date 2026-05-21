<?php

declare(strict_types=1);

namespace App\Infrastructure\Bulk;

/**
 * Result of a {@see BulkImportRunner::run()} invocation (D17).
 *
 * Carries totals and a per-row error list so the importer's caller can
 * render a "X rows succeeded, Y failed (see details)" UI without parsing
 * logs.
 */
final readonly class ImportSummary
{
    /**
     * @param int                                   $processed
     * @param int                                   $succeeded
     * @param int                                   $failed
     * @param list<array{line: int, error: string}> $errors
     * @param bool                                  $dryRun
     */
    public function __construct(
        public int $processed,
        public int $succeeded,
        public int $failed,
        public array $errors,
        public bool $dryRun
    ) {
    }

    /**
     * isFullySuccessful.
     *
     * @return bool
     */
    public function isFullySuccessful(): bool
    {
        return $this->failed === 0;
    }
}
