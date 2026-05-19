<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bulk;

use App\Infrastructure\Bulk\BulkImportInterface;
use App\Infrastructure\Bulk\BulkImportRunner;
use App\Infrastructure\Bulk\CsvReader;
use Psr\Log\NullLogger;
use Tests\Support\UnitTestCase;

final class BulkImportRunnerTest extends UnitTestCase
{
    public function test_happy_path_processes_every_row(): void
    {
        $importer = new SpyImporter();
        $csv = "email,name\na@b.c,Alice\nx@y.z,Bob\n";

        $summary = (new BulkImportRunner(new NullLogger()))
            ->run($importer, CsvReader::fromString($csv));

        $this->assertSame(2, $summary->processed);
        $this->assertSame(2, $summary->succeeded);
        $this->assertSame(0, $summary->failed);
        $this->assertSame([], $summary->errors);
        $this->assertCount(2, $importer->processed);
    }

    public function test_dry_run_maps_but_does_not_process(): void
    {
        $importer = new SpyImporter();
        $csv = "email,name\na@b.c,Alice\nx@y.z,Bob\n";

        $summary = (new BulkImportRunner(new NullLogger()))
            ->run($importer, CsvReader::fromString($csv), dryRun: true);

        $this->assertSame(2, $summary->succeeded);
        $this->assertTrue($summary->dryRun);
        $this->assertCount(0, $importer->processed, 'process() must NOT be called in dry-run mode');
    }

    public function test_row_level_failures_are_captured_per_row(): void
    {
        $importer = new SpyImporter();
        $importer->failOnEmail = 'x@y.z';
        $csv = "email,name\na@b.c,Alice\nx@y.z,Bob\nok@now.com,Carol\n";

        $summary = (new BulkImportRunner(new NullLogger()))
            ->run($importer, CsvReader::fromString($csv));

        $this->assertSame(3, $summary->processed);
        $this->assertSame(2, $summary->succeeded);
        $this->assertSame(1, $summary->failed);
        $this->assertCount(1, $summary->errors);
        $this->assertSame(3, $summary->errors[0]['line']);
        $this->assertStringContainsString('boom', $summary->errors[0]['error']);
    }

    public function test_missing_required_column_throws_before_processing(): void
    {
        $importer = new SpyImporter();
        $csv = "name\nAlice\n"; // missing 'email'

        try {
            (new BulkImportRunner(new NullLogger()))
                ->run($importer, CsvReader::fromString($csv));
            $this->fail('Expected InvalidArgumentException for missing column');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $this->assertCount(0, $importer->processed);
    }

    public function test_empty_csv_with_required_columns_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BulkImportRunner(new NullLogger()))
            ->run(new SpyImporter(), CsvReader::fromString(''));
    }

    public function test_process_failure_is_captured_separately_from_map_failure(): void
    {
        $importer = new SpyImporter();
        $importer->processFailsOnEmail = 'a@b.c';
        $csv = "email,name\na@b.c,Alice\nok@now.com,Bob\n";

        $summary = (new BulkImportRunner(new NullLogger()))
            ->run($importer, CsvReader::fromString($csv));

        $this->assertSame(1, $summary->failed);
        $this->assertSame(1, $summary->succeeded);
        $this->assertStringContainsString('process boom', $summary->errors[0]['error']);
    }

    public function test_is_fully_successful_flag(): void
    {
        $importer = new SpyImporter();
        $csv = "email,name\na@b.c,Alice\n";

        $summary = (new BulkImportRunner(new NullLogger()))
            ->run($importer, CsvReader::fromString($csv));

        $this->assertTrue($summary->isFullySuccessful());
    }
}

final class SpyImporter implements BulkImportInterface
{
    /** @var list<object> */
    public array $processed = [];

    public ?string $failOnEmail = null;
    public ?string $processFailsOnEmail = null;

    public function name(): string
    {
        return 'spy';
    }

    /**
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return ['email', 'name'];
    }

    /**
     * @param array<string, string> $row
     */
    public function mapRow(array $row): object
    {
        if ($this->failOnEmail !== null && $row['email'] === $this->failOnEmail) {
            throw new \RuntimeException('boom: ' . $row['email']);
        }
        return (object) $row;
    }

    public function process(object $command): void
    {
        /** @phpstan-ignore-next-line dynamic access on stdClass fixture */
        if ($this->processFailsOnEmail !== null && $command->email === $this->processFailsOnEmail) {
            throw new \RuntimeException('process boom');
        }
        $this->processed[] = $command;
    }
}
