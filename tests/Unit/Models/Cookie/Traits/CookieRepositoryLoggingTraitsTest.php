<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Cookie\Traits;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Models\Cookie\Traits\BusinessMetricsLogging;
use App\Models\Cookie\Traits\RepositoryLogging;
use Config\Logging;
use Psr\Log\AbstractLogger;
use Tests\Support\UnitTestCase;

/**
 * Pins the Cookie repository logging trait surface.
 *
 * The two traits (RepositoryLogging + BusinessMetricsLogging) ship with private
 * helpers that the integration tests touch only on their happy path. This test
 * exercises the threshold, fail, and disabled branches directly via an
 * anonymous host class that mirrors the CookieRepository constructor shape.
 */
final class CookieRepositoryLoggingTraitsTest extends UnitTestCase
{
    public function test_log_save_error_emits_structured_error(): void
    {
        $host = $this->makeHost();
        $host->callLogSaveError(new \RuntimeException('boom'));

        $records = $host->logger->records;
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame('Database error during save', $records[0]['message']);
        $this->assertSame('boom', $records[0]['context']['exception']);
    }

    public function test_log_delete_and_query_errors_emit_structured_errors(): void
    {
        $host = $this->makeHost();
        $host->callLogDeleteError(new \RuntimeException('delete-failed'));
        $host->callLogQueryError('findById', new \RuntimeException('query-failed'));

        $this->assertSame('Database error during delete', $host->logger->records[0]['message']);
        $this->assertSame('Query failed', $host->logger->records[1]['message']);
        $this->assertSame('findById', $host->logger->records[1]['context']['method']);
    }

    public function test_log_slow_query_skips_when_below_threshold(): void
    {
        $host = $this->makeHost();
        $host->callLogSlowQuery('findAll', microtime(true), 5, ['k' => 'v']);

        $this->assertSame([], $host->logger->records);
    }

    public function test_log_slow_query_warns_when_above_threshold(): void
    {
        $host = $this->makeHost();
        // Simulate a query that "started" 5s ago — well above the 100ms threshold.
        $host->callLogSlowQuery('findAll', microtime(true) - 5.0, 12, ['scope' => 'all']);

        $this->assertCount(1, $host->logger->records);
        $this->assertSame('warning', $host->logger->records[0]['level']);
        $this->assertSame('Slow query detected', $host->logger->records[0]['message']);
        $this->assertGreaterThan(100, $host->logger->records[0]['context']['duration_ms']);
        $this->assertSame('all', $host->logger->records[0]['context']['scope']);
    }

    public function test_log_slow_paginated_query_warns_when_above_threshold(): void
    {
        $host = $this->makeHost();
        $result = ['data' => [1, 2, 3], 'total' => 3, 'page' => 1, 'perPage' => 10, 'lastPage' => 1];
        $host->callLogSlowPaginatedQuery('findPaginated', microtime(true) - 5.0, $result, 1, 10, 'choc');

        $this->assertCount(1, $host->logger->records);
        $this->assertSame('warning', $host->logger->records[0]['level']);
        $this->assertSame('findPaginated', $host->logger->records[0]['context']['method']);
        $this->assertSame('choc', $host->logger->records[0]['context']['searchTerm']);
    }

    public function test_log_business_metrics_skips_when_disabled(): void
    {
        $host = $this->makeHost(metricsEnabled: false);
        $host->callLogBusinessMetrics($this->cookie(stock: 1), 42, null);

        $this->assertSame([], $host->logger->records);
    }

    public function test_low_stock_alert_fires_below_threshold(): void
    {
        $host = $this->makeHost();
        $host->callLogBusinessMetrics($this->cookie(stock: 3), 7, null);

        $this->assertCount(1, $host->logger->records);
        $this->assertSame('Low stock alert', $host->logger->records[0]['message']);
        $this->assertSame(3, $host->logger->records[0]['context']['stock']);
    }

    public function test_price_change_skipped_when_old_price_is_null(): void
    {
        $host = $this->makeHost();
        $host->callLogBusinessMetrics($this->cookie(stock: 50), 1, null);
        $this->assertSame([], $host->logger->records, 'no oldPrice and healthy stock ⇒ no log');
    }

    public function test_minor_price_change_below_threshold_is_skipped(): void
    {
        $host = $this->makeHost();
        // 1% change, below the 10% default threshold.
        $host->callLogBusinessMetrics(
            $this->cookie(stock: 50, priceMinor: 101),
            7,
            CookiePrice::fromMinorUnits(100)
        );

        $priceLogs = array_filter($host->logger->records, fn ($r) => $r['message'] === 'Significant price change');
        $this->assertSame([], $priceLogs);
    }

    public function test_significant_price_change_is_logged(): void
    {
        $host = $this->makeHost();
        $host->callLogBusinessMetrics(
            $this->cookie(stock: 50, priceMinor: 200),
            7,
            CookiePrice::fromMinorUnits(100)
        );

        $this->assertCount(1, $host->logger->records);
        $this->assertSame('Significant price change', $host->logger->records[0]['message']);
        $this->assertSame(100.0, $host->logger->records[0]['context']['changePercent']);
    }

    public function test_track_popular_cookie_fires_after_threshold(): void
    {
        $host = $this->makeHost();
        // Default threshold is 100; bump past it.
        for ($i = 0; $i < 101; $i++) {
            $host->callTrackPopularCookie(7);
        }

        $popular = array_filter($host->logger->records, fn ($r) => $r['message'] === 'Popular cookie');
        $this->assertCount(1, $popular);
    }

    public function test_track_popular_cookie_skipped_when_metrics_disabled(): void
    {
        $host = $this->makeHost(metricsEnabled: false);
        for ($i = 0; $i < 200; $i++) {
            $host->callTrackPopularCookie(7);
        }
        $this->assertSame([], $host->logger->records);
    }

    private function makeHost(bool $metricsEnabled = true): object
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param mixed              $level
             * @param string|\Stringable $message
             * @param array<mixed>       $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $config = new Logging();
        $config->businessMetricsEnabled = $metricsEnabled;

        return new class ($logger, $config) {
            use RepositoryLogging;
            use BusinessMetricsLogging;

            public function __construct(public AbstractLogger $logger, public Logging $loggingConfig)
            {
            }

            public function callLogSaveError(\Throwable $e): void
            {
                $this->logSaveError($e);
            }

            public function callLogDeleteError(\Throwable $e): void
            {
                $this->logDeleteError($e);
            }

            public function callLogQueryError(string $m, \Throwable $e): void
            {
                $this->logQueryError($m, $e);
            }

            /** @param array<string, mixed> $context */
            public function callLogSlowQuery(string $m, float $start, int $count, array $context): void
            {
                $this->logSlowQuery($m, $start, $count, $context);
            }

            /** @param array{data: array<int, mixed>, total: int, page: int, perPage: int, lastPage: int} $r */
            public function callLogSlowPaginatedQuery(string $m, float $s, array $r, int $p, int $pp, ?string $q): void
            {
                $this->logSlowPaginatedQuery($m, $s, $r, $p, $pp, $q);
            }

            public function callLogBusinessMetrics(Cookie $c, int $id, ?CookiePrice $old): void
            {
                $this->logBusinessMetrics($c, $id, $old);
            }

            public function callTrackPopularCookie(int $id): void
            {
                $this->trackPopularCookie($id);
            }
        };
    }

    private function cookie(int $stock, int $priceMinor = 500): Cookie
    {
        return Cookie::create(
            CookieName::fromString('Test Cookie'),
            null,
            CookiePrice::fromMinorUnits($priceMinor),
            $stock
        );
    }
}
