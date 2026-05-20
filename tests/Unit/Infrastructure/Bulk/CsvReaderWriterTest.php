<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bulk;

use App\Infrastructure\Bulk\CsvReader;
use App\Infrastructure\Bulk\CsvWriter;
use Tests\Support\UnitTestCase;

final class CsvReaderWriterTest extends UnitTestCase
{
    public function test_reader_yields_associative_rows_keyed_by_header(): void
    {
        $csv = "name,email\nAlice,a@b.c\nBob,b@c.d\n";

        $rows = iterator_to_array(CsvReader::fromString($csv)->rows());

        $this->assertCount(2, $rows);
        $this->assertSame(['name' => 'Alice', 'email' => 'a@b.c'], $rows[2]);
        $this->assertSame(['name' => 'Bob', 'email' => 'b@c.d'], $rows[3]);
    }

    public function test_reader_strips_utf8_bom_from_first_header_cell(): void
    {
        $csv = "\xEF\xBB\xBFname,email\nAlice,a@b.c\n";

        $rows = iterator_to_array(CsvReader::fromString($csv)->rows());

        $row = array_values($rows)[0];
        $this->assertSame('Alice', $row['name']);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey("\xEF\xBB\xBFname", $row);
    }

    public function test_reader_throws_when_row_has_wrong_column_count(): void
    {
        $csv = "a,b,c\n1,2\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('row 2 has 2 columns');

        iterator_to_array(CsvReader::fromString($csv)->rows());
    }

    public function test_reader_handles_quoted_values_with_commas_and_newlines(): void
    {
        $csv = "name,note\n\"Acme, Inc.\",\"line1\nline2\"\n";

        $rows = iterator_to_array(CsvReader::fromString($csv)->rows());

        $row = array_values($rows)[0];
        $this->assertSame('Acme, Inc.', $row['name']);
        $this->assertSame("line1\nline2", $row['note']);
    }

    public function test_writer_emits_header_and_rows_in_declared_order(): void
    {
        $writer = CsvWriter::toString(['id', 'name', 'price']);

        $writer->writeRow(['name' => 'Choc Chip', 'price' => '2.99', 'id' => 1]);
        $writer->writeRow(['name' => 'Oatmeal', 'price' => '3.50', 'id' => 2]);

        $out = $writer->contents();

        $this->assertStringStartsWith("id,name,price\n", $out);
        $this->assertStringContainsString('1,"Choc Chip",2.99', $out);
        $this->assertStringContainsString('2,Oatmeal,3.50', $out);
    }

    public function test_writer_writes_empty_string_for_missing_columns(): void
    {
        $writer = CsvWriter::toString(['id', 'name', 'notes']);

        $writer->writeRow(['id' => 1, 'name' => 'A']);

        // 'notes' is missing in the row — writer must emit it as empty string.
        $out = $writer->contents();
        $lines = array_values(array_filter(explode("\n", $out), static fn(string $line): bool => $line !== ''));
        $this->assertSame('1,A,', $lines[1]);
    }

    public function test_round_trip_through_writer_then_reader(): void
    {
        $writer = CsvWriter::toString(['id', 'amount']);
        $writer->writeRow(['id' => '7', 'amount' => '12.50']);
        $writer->writeRow(['id' => '8', 'amount' => '99.99']);

        $rows = iterator_to_array(CsvReader::fromString($writer->contents())->rows());

        $this->assertCount(2, $rows);
        $values = array_values($rows);
        $this->assertSame('7', $values[0]['id']);
        $this->assertSame('12.50', $values[0]['amount']);
        $this->assertSame('8', $values[1]['id']);
    }
}
