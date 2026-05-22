<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedHandler;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedQuery;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\SystemClock;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Ports\LogConfigPort;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class GetCookiesPaginatedHandlerTest extends UnitTestCase
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

    public function test_returns_paginated_results(): void
    {
        $this->stubConfig(level: 'errors');
        $expected = $this->paginationResult($this->makeDtos(20), total: 100, page: 1, perPage: 20, lastPage: 5);

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, null, false)
            ->willReturn($expected);

        $result = $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));

        $this->assertSame(100, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertContainsOnlyInstancesOf(CookieDTO::class, $result['data']);
    }

    public function test_search_term_is_passed_to_repository(): void
    {
        $this->stubConfig(level: 'errors');
        $expected = $this->paginationResult($this->makeDtos(5), total: 5, page: 1, perPage: 20, lastPage: 1);

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, 'Chocolate', false)
            ->willReturn($expected);

        $result = $this->makeHandler()->handle(
            new GetCookiesPaginatedQuery(page: 1, perPage: 20, searchTerm: 'Chocolate')
        );

        $this->assertCount(5, $result['data']);
    }

    public function test_include_inactive_is_passed_to_repository(): void
    {
        $this->stubConfig(level: 'errors');
        $expected = $this->paginationResult([], total: 0, page: 1, perPage: 20, lastPage: 1);

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, null, true)
            ->willReturn($expected);

        $this->makeHandler()->handle(
            new GetCookiesPaginatedQuery(page: 1, perPage: 20, searchTerm: null, includeInactive: true)
        );
    }

    public function test_search_queries_are_always_logged_for_analytics(): void
    {
        // Default level is 'errors' (which would skip logging) — but a search
        // term must force-log for analytics regardless of level.
        $this->stubConfig(level: 'errors');
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult($this->makeDtos(2), total: 2, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(function (array $ctx): bool {
                return $ctx['searchTerm'] === 'gluten free' && $ctx['result_count'] === 2;
            }));

        $this->makeHandler()->handle(
            new GetCookiesPaginatedQuery(page: 1, perPage: 20, searchTerm: 'gluten free')
        );
    }

    public function test_empty_string_search_term_is_treated_as_no_search(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult([], total: 0, page: 1, perPage: 20, lastPage: 1)
        );

        // Empty string search term => not a search query => 'errors' mode is silent.
        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(
            new GetCookiesPaginatedQuery(page: 1, perPage: 20, searchTerm: '')
        );
    }

    public function test_all_level_logs_every_call(): void
    {
        $this->stubConfig(level: 'all');
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult($this->makeDtos(2), total: 2, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(function (array $ctx): bool {
                return !isset($ctx['searchTerm']) && !isset($ctx['slow_query']);
            }));

        $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));
    }

    public function test_slow_query_short_circuits_irrespective_of_level(): void
    {
        // Post-E08, slow queries promote to `warning` (closes 04/F7).
        $this->stubConfig(level: 'errors', slowMs: 0);
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult($this->makeDtos(1), total: 1, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Slow query executed', $this->callback(function (array $ctx): bool {
                return ($ctx['slow_query'] ?? false) === true;
            }));

        $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));
    }

    public function test_sampling_with_rate_one_always_logs(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 1.0);
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult([], total: 0, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));
    }

    public function test_sampling_with_rate_zero_never_logs(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 0.0);
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult([], total: 0, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));
    }

    public function test_unknown_logging_level_falls_back_to_silent(): void
    {
        $this->stubConfig(level: 'foo');
        $this->repository->method('findPaginated')->willReturn(
            $this->paginationResult([], total: 0, page: 1, perPage: 20, lastPage: 1)
        );

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookiesPaginatedQuery(page: 1, perPage: 20));
    }

    public function test_do_handle_is_under_the_twenty_line_ceiling(): void
    {
        $method = (new \ReflectionClass(GetCookiesPaginatedHandler::class))->getMethod('doHandle');
        $end = $method->getEndLine();
        $start = $method->getStartLine();
        $this->assertNotFalse($end);
        $this->assertNotFalse($start);
        $lines = ($end - $start) - 1;
        $this->assertLessThanOrEqual(
            20,
            $lines,
            sprintf('GetCookiesPaginatedHandler::doHandle() is %d lines; CLAUDE.md caps it at 20.', $lines)
        );
    }

    /**
     * Query DTO enforces the page ceiling — closes 04/F6.
     */
    public function test_query_constructor_caps_page_overflow(): void
    {
        $this->expectException(ValidationException::class);
        new GetCookiesPaginatedQuery(page: GetCookiesPaginatedQuery::MAX_PAGE + 1);
    }

    /**
     * Query DTO length-caps the search term — closes 04/F4.
     */
    public function test_query_constructor_rejects_overlong_search_term(): void
    {
        $this->expectException(ValidationException::class);
        new GetCookiesPaginatedQuery(
            page: 1,
            perPage: 20,
            searchTerm: str_repeat('a', GetCookiesPaginatedQuery::MAX_SEARCH_LENGTH + 1)
        );
    }

    /**
     * Query DTO LIKE-escapes `%`, `_`, and `\` — closes 04/F4 (the
     * repository receives an already-safe term).
     */
    public function test_query_constructor_like_escapes_wildcards(): void
    {
        $query = new GetCookiesPaginatedQuery(
            page: 1,
            perPage: 20,
            searchTerm: '50% off_now'
        );
        $this->assertSame('50\\% off\\_now', $query->searchTerm);
    }

    private function makeHandler(): GetCookiesPaginatedHandler
    {
        return new GetCookiesPaginatedHandler(
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
     * @param list<CookieDTO> $data
     * @return array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}
     */
    private function paginationResult(array $data, int $total, int $page, int $perPage, int $lastPage): array
    {
        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => $lastPage,
        ];
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
