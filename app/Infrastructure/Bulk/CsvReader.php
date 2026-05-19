<?php

declare(strict_types=1);

namespace App\Infrastructure\Bulk;

/**
 * Memory-friendly CSV reader (D17).
 *
 * Streams rows out of an open file handle (or in-memory string) one at a
 * time so importing a 200k-row spreadsheet doesn't OOM. Returns each row
 * as an associative array keyed by the header row.
 *
 * Usage:
 *     foreach (CsvReader::fromString($csvText)->rows() as $row) {
 *         $command = new ImportRow($row['email'], $row['name']);
 *         $bus->dispatch($command);
 *     }
 *
 * The reader is intentionally header-strict: a row with fewer or more
 * columns than the header is reported as an error so importers fail loud
 * instead of silently shifting columns.
 */
final class CsvReader
{
    /** @var resource */
    private $handle;

    private bool $ownsHandle;

    /**
     * @param resource $handle
     */
    private function __construct(
        $handle,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
        bool $ownsHandle = false
    ) {
        $this->handle = $handle;
        $this->ownsHandle = $ownsHandle;
    }

    public static function fromString(string $csv, string $delimiter = ',', string $enclosure = '"'): self
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open php://temp for CSV reading.');
        }
        fwrite($handle, $csv);
        rewind($handle);

        return new self($handle, $delimiter, $enclosure, '\\', true);
    }

    public static function fromFile(string $path, string $delimiter = ',', string $enclosure = '"'): self
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open %s for CSV reading.', $path));
        }

        return new self($handle, $delimiter, $enclosure, '\\', true);
    }

    /**
     * @return iterable<int, array<string, string>>
     */
    public function rows(): iterable
    {
        $header = $this->readRow();
        if ($header === null) {
            return;
        }

        $lineNumber = 1;
        while (($row = $this->readRow()) !== null) {
            $lineNumber++;
            if (count($row) !== count($header)) {
                throw new \RuntimeException(sprintf(
                    'CSV row %d has %d columns; header has %d.',
                    $lineNumber,
                    count($row),
                    count($header)
                ));
            }
            /** @var array<string, string> $mapped */
            $mapped = array_combine($header, $row);
            yield $lineNumber => $mapped;
        }
    }

    public function close(): void
    {
        if (!$this->ownsHandle) {
            return;
        }
        fclose($this->handle);
        $this->ownsHandle = false;
    }

    /**
     * @return list<string>|null
     */
    private function readRow(): ?array
    {
        $row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape);
        if ($row === false) {
            return null;
        }
        // fgetcsv returns [null] for a blank line; treat it as end-of-data.
        if (count($row) === 1 && $row[0] === null) {
            return null;
        }
        $strings = array_map(static fn($v): string => $v ?? '', $row);
        // Trim BOM from the first cell of the header (common pasted-from-Excel case).
        $stripped = preg_replace('/^\xEF\xBB\xBF/', '', $strings[0]);
        if ($stripped !== null) {
            $strings[0] = $stripped;
        }
        return $strings;
    }

    public function __destruct()
    {
        $this->close();
    }
}
