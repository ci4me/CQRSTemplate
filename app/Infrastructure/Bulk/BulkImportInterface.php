<?php

declare(strict_types=1);

namespace App\Infrastructure\Bulk;

/**
 * Per-domain row-to-command mapping for bulk CSV imports (D17).
 *
 * Implementations describe one row's worth of work. The runner
 * ({@see BulkImportRunner}) provides the surrounding mechanics — header
 * validation, dry-run mode, error collection, summary reporting.
 *
 * Each implementation typically:
 *  - declares its required column names via `requiredColumns()`
 *  - constructs a domain command from the row in `mapRow()` (or throws to
 *    skip the row with an error)
 *  - dispatches the command in `process()` (default: through the CommandBus)
 */
interface BulkImportInterface
{
    /**
     * Stable identifier for the import — used in logs and the summary
     * (e.g. "cookies.import").
     *
     * @return string
     */
    public function name(): string;

    /**
     * Header columns that MUST be present. The runner fails fast if any
     * are missing so the user doesn't waste time on a malformed file.
     *
     * @return list<string>
     */
    public function requiredColumns(): array;

    /**
     * Convert one CSV row into a domain command (or any callable target).
     * Throw to skip the row with an error captured in the summary.
     *
     * @param array<string, string> $row
     * @return object
     */
    public function mapRow(array $row): object;

    /**
     * Execute the command produced by mapRow(). The default implementation
     * dispatches through the CommandBus, but importers can override to
     * call services directly (e.g. for bulk inserts).
     *
     * @param object $command
     * @return void
     */
    public function process(object $command): void;
}
