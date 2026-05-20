<?php

declare(strict_types=1);

namespace App\Infrastructure\Bulk;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wraps a {@see BulkImportInterface} with the bulk-import mechanics (D17):
 *  - validate the CSV header against the required columns
 *  - stream rows through the importer's mapRow + process
 *  - collect per-row errors instead of aborting on the first one
 *  - support a dry-run mode that maps rows but does NOT call process()
 *  - return an {@see ImportSummary}
 *
 * Error policy:
 *  - mapRow() throwing is captured as a row-level error (the import keeps going).
 *  - process() throwing is also captured per-row.
 *  - Missing required columns is a fail-fast \InvalidArgumentException
 *    BEFORE any row is processed.
 *
 * Memory: the runner consumes the CsvReader iterator one row at a time
 * and never accumulates rows in memory.
 */
final class BulkImportRunner
{
    /**
     * __construct.
     *
     * @param LoggerInterface $logger
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * run.
     *
     * @param BulkImportInterface $importer
     * @param CsvReader           $reader
     * @param bool                $dryRun
     * @return ImportSummary
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function run(BulkImportInterface $importer, CsvReader $reader, bool $dryRun = false): ImportSummary
    {
        $iterator = $reader->rows();
        if (!$iterator instanceof \Generator) {
            $iterator = (static function () use ($iterator) {
                yield from $iterator;
            })();
        }

        $this->validateHeaderOrThrow($importer, $iterator);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        foreach ($iterator as $lineNumber => $row) {
            $processed++;
            try {
                $command = $importer->mapRow($row);
                if (!$dryRun) {
                    $importer->process($command);
                }
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['line' => $lineNumber, 'error' => $e->getMessage()];
                $this->logger->warning('Bulk import row failed', [
                    'component' => 'BulkImportRunner',
                    'importer' => $importer->name(),
                    'line' => $lineNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Bulk import completed', [
            'component' => 'BulkImportRunner',
            'importer' => $importer->name(),
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ]);

        return new ImportSummary($processed, $succeeded, $failed, $errors, $dryRun);
    }

    /**
     * Peek the first row to learn the header, then put it back via a
     * push-front buffer. Generators in PHP can't rewind, so we wrap the
     * iterator in a small helper that re-yields the first row before
     * deferring to the original.
     */
    /**
     * @param BulkImportInterface                    $importer
     * @param \Generator<int, array<string, string>> $iterator
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateHeaderOrThrow(BulkImportInterface $importer, \Generator &$iterator): void
    {
        if (!$iterator->valid()) {
            // Empty file: synthesise an empty header → fail loudly if anything is required.
            $missing = $importer->requiredColumns();
            if ($missing !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'CSV is empty; importer "%s" requires columns: %s.',
                    $importer->name(),
                    implode(', ', $missing)
                ));
            }
            return;
        }

        $firstRow = $iterator->current();
        $headerKeys = array_keys($firstRow);

        $missing = array_diff($importer->requiredColumns(), $headerKeys);
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'CSV is missing required columns for "%s": %s. Found: %s.',
                $importer->name(),
                implode(', ', $missing),
                implode(', ', $headerKeys)
            ));
        }
    }
}
