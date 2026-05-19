<?php

declare(strict_types=1);

namespace App\Infrastructure\Bulk;

/**
 * Memory-friendly CSV writer (D17).
 *
 * Companion to CsvReader for exports: write a header once, then
 * `writeRow()` per record. Each row is an associative array; the writer
 * picks values in the header order so callers can keep field order in one
 * place.
 *
 * Usage:
 *     $writer = CsvWriter::toString(['id', 'name', 'price']);
 *     foreach ($cookies as $cookie) {
 *         $writer->writeRow([
 *             'id'    => $cookie->id,
 *             'name'  => $cookie->name,
 *             'price' => $cookie->priceFormatted,
 *         ]);
 *     }
 *     return $writer->contents();
 */
final class CsvWriter
{
    /** @var resource */
    private $handle;
    private bool $ownsHandle;

    /**
     * @param resource    $handle
     * @param list<string> $header
     */
    private function __construct(
        $handle,
        private readonly array $header,
        private readonly string $delimiter,
        private readonly string $enclosure,
        bool $ownsHandle
    ) {
        $this->handle = $handle;
        $this->ownsHandle = $ownsHandle;

        if ($header === []) {
            return;
        }
        $this->putRow($header);
    }

    /**
     * @param list<string> $header
     */
    public static function toString(array $header, string $delimiter = ',', string $enclosure = '"'): self
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open php://temp for CSV writing.');
        }
        return new self($handle, $header, $delimiter, $enclosure, true);
    }

    /**
     * @param list<string> $header
     */
    public static function toFile(string $path, array $header, string $delimiter = ',', string $enclosure = '"'): self
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open %s for CSV writing.', $path));
        }
        return new self($handle, $header, $delimiter, $enclosure, true);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public function writeRow(array $row): void
    {
        $line = [];
        foreach ($this->header as $col) {
            $value = $row[$col] ?? '';
            $line[] = $this->stringify($value);
        }
        $this->putRow($line);
    }

    public function contents(): string
    {
        rewind($this->handle);
        $out = stream_get_contents($this->handle);
        return $out === false ? '' : $out;
    }

    public function close(): void
    {
        if (!$this->ownsHandle) {
            return;
        }
        fclose($this->handle);
        $this->ownsHandle = false;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    /**
     * @param list<string> $row
     */
    private function putRow(array $row): void
    {
        $result = fputcsv($this->handle, $row, $this->delimiter, $this->enclosure, '\\');
        if ($result === false) {
            throw new \RuntimeException('Failed to write CSV row.');
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
