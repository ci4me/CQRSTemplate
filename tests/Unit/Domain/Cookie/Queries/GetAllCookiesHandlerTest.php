<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesHandler;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesQuery;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\SystemClock;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Ports\LogConfigPort;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class GetAllCookiesHandlerTest extends UnitTestCase
{
    private CookieQueryRepositoryInterface $repository;
    private LoggerInterface $logger;
    private LogConfigPort $loggingConfig;
    private float $samplingRate = 0.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieQueryRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loggingConfig = $this->createMock(LogConfigPort::class);
    }

    public function test_returns_all_active_cookies_by_default(): void
    {
        $this->stubConfig(level: 'errors');
        $query = new GetAllCookiesQuery();
        $dtos = $this->makeDtos(3);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(false)
            ->willReturn($dtos);

        $result = $this->makeHandler()->handle($query);

        $this->assertCount(3, $result);
        $this->assertContainsOnlyInstancesOf(CookieDTO::class, $result);
    }

    public function test_returns_all_cookies_including_inactive(): void
    {
        $this->stubConfig(level: 'errors');
        $query = new GetAllCookiesQuery(includeInactive: true);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(true)
            ->willReturn($this->makeDtos(5));

        $result = $this->makeHandler()->handle($query);

        $this->assertCount(5, $result);
    }

    public function test_returns_empty_array_when_no_cookies(): void
    {
        $this->stubConfig(level: 'errors');

        $this->repository->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->makeHandler()->handle(new GetAllCookiesQuery());

        $this->assertSame([], $result);
    }

    public function test_logs_every_call_when_level_is_all(): void
    {
        $this->stubConfig(level: 'all');
        $this->repository->method('findAll')->willReturn($this->makeDtos(2));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(function (array $ctx): bool {
                return $ctx['domain'] === 'Cookie'
                    && $ctx['query'] === GetAllCookiesQuery::class
                    && $ctx['result_count'] === 2
                    && !isset($ctx['slow_query']);
            }));

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_does_not_log_when_level_is_errors_and_not_slow(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_does_not_log_when_level_is_slow_and_query_is_fast(): void
    {
        $this->stubConfig(level: 'slow');
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_logs_slow_query_regardless_of_level(): void
    {
        // threshold=0 forces ANY measured duration > 0ms to count as slow.
        // Post-E08 slow queries promote to warning (closes 04/F7).
        $this->stubConfig(level: 'errors', slowMs: 0);
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Slow query executed', $this->callback(function (array $ctx): bool {
                return ($ctx['slow_query'] ?? false) === true;
            }));

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_logs_when_sampling_rate_is_one(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 1.0);
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_does_not_log_when_sampling_rate_is_zero(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 0.0);
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_unknown_logging_level_falls_back_to_silent(): void
    {
        // Default match arm must yield false (no log).
        $this->stubConfig(level: 'unrecognized');
        $this->repository->method('findAll')->willReturn($this->makeDtos(1));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    public function test_do_handle_is_under_the_twenty_line_ceiling(): void
    {
        $method = (new \ReflectionClass(GetAllCookiesHandler::class))->getMethod('doHandle');
        $end = $method->getEndLine();
        $start = $method->getStartLine();
        $this->assertNotFalse($end);
        $this->assertNotFalse($start);
        $lines = ($end - $start) - 1;
        $this->assertLessThanOrEqual(
            20,
            $lines,
            sprintf('GetAllCookiesHandler::doHandle() is %d lines; CLAUDE.md caps it at 20.', $lines)
        );
    }

    /**
     * E08 hard upper bound (closes 04/F2). The handler throws a
     * ValidationException with the dedicated
     * {@see \App\Domain\Cookie\ErrorCodes::COOKIE_QUERY_RESULT_LIMIT_EXCEEDED}
     * code when the repository returns more than
     * {@see GetAllCookiesQuery::MAX_RESULTS} rows.
     */
    public function test_get_all_cookies_caps_at_max_results(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->method('findAll')
            ->willReturn($this->makeDtos(GetAllCookiesQuery::MAX_RESULTS + 1));

        $this->expectException(ValidationException::class);

        $this->makeHandler()->handle(new GetAllCookiesQuery());
    }

    private function makeHandler(): GetAllCookiesHandler
    {
        return new GetAllCookiesHandler(
            $this->repository,
            $this->logger,
            new SystemClock(),
            new LogSampler($this->samplingRate),
            $this->loggingConfig
        );
    }

    private function stubConfig(
        string $level,
        int $slowMs = 1_000_000,
        float $samplingRate = 0.0,
    ): void {
        $this->loggingConfig->method('queryLoggingLevel')->willReturn($level);
        $this->loggingConfig->method('slowQueryThresholdMs')->willReturn($slowMs);
        $this->loggingConfig->method('samplingRate')->willReturn($samplingRate);
        $this->samplingRate = $samplingRate;
    }

    /**
     * @return list<CookieDTO>
     */
    private function makeDtos(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = new CookieDTO(
                id: $i,
                name: "Cookie {$i}",
                description: null,
                price: '1.00',
                formattedPrice: '$1.00',
                stock: 10,
                isActive: true,
                createdAt: '2025-10-21 10:00:00',
                updatedAt: null
            );
        }
        return $out;
    }
}
