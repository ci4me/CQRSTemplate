<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdHandler;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\SystemClock;
use App\Domain\Shared\Ports\LogConfigPort;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class GetCookieByIdHandlerTest extends UnitTestCase
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

    public function test_returns_cookie_when_found(): void
    {
        $this->stubConfig(level: 'errors');
        $expected = $this->makeDto(1, 'Chip');

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($expected);

        $result = $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));

        $this->assertInstanceOf(CookieDTO::class, $result);
        $this->assertSame(1, $result->id);
    }

    public function test_returns_null_when_not_found(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->assertNull($this->makeHandler()->handle(new GetCookieByIdQuery(id: 999)));
    }

    public function test_errors_level_logs_only_not_found_results(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->method('findById')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(function (array $ctx): bool {
                return $ctx['result'] === 'not_found' && $ctx['cookieId'] === 42;
            }));

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 42));
    }

    public function test_errors_level_does_not_log_found_results(): void
    {
        $this->stubConfig(level: 'errors');
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_all_level_logs_every_call(): void
    {
        $this->stubConfig(level: 'all');
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(function (array $ctx): bool {
                return $ctx['result'] === 'found' && !isset($ctx['slow_query']);
            }));

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_slow_level_does_not_log_fast_queries(): void
    {
        $this->stubConfig(level: 'slow');
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_slow_query_short_circuits_irrespective_of_level(): void
    {
        // Slow queries promote to `warning` (closes 04/F7) regardless of
        // the configured logging level — operators losing slow-query
        // alerts is a real prod regression risk and the new base owns
        // that escalation.
        $this->stubConfig(level: 'errors', slowMs: 0);
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Slow query executed', $this->callback(function (array $ctx): bool {
                return ($ctx['slow_query'] ?? false) === true && $ctx['result'] === 'found';
            }));

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_sampling_with_rate_one_always_logs(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 1.0);
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_sampling_with_rate_zero_never_logs(): void
    {
        $this->stubConfig(level: 'sampling', samplingRate: 0.0);
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    public function test_unknown_logging_level_falls_back_to_silent(): void
    {
        $this->stubConfig(level: 'mystery');
        $this->repository->method('findById')->willReturn($this->makeDto(1, 'X'));

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetCookieByIdQuery(id: 1));
    }

    private function makeHandler(): GetCookieByIdHandler
    {
        return new GetCookieByIdHandler(
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
        // The post-E08 handler accepts the LogSampler at construction
        // (not per-call), so the test captures the rate here for
        // makeHandler() to build a sampler with the same probability.
        $this->samplingRate = $samplingRate;
    }

    public function test_do_handle_is_under_the_twenty_line_ceiling(): void
    {
        $method = (new \ReflectionClass(GetCookieByIdHandler::class))->getMethod('doHandle');
        $end = $method->getEndLine();
        $start = $method->getStartLine();
        $this->assertNotFalse($end);
        $this->assertNotFalse($start);
        $lines = ($end - $start) - 1;
        $this->assertLessThanOrEqual(
            20,
            $lines,
            sprintf('GetCookieByIdHandler::doHandle() is %d lines; CLAUDE.md caps it at 20.', $lines)
        );
    }

    private function makeDto(int $id, string $name): CookieDTO
    {
        return new CookieDTO(
            id: $id,
            name: $name,
            description: 'A cookie',
            price: '2.99',
            formattedPrice: '$2.99',
            stock: 5,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: null
        );
    }
}
