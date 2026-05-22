<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Queries;

use App\Domain\Shared\Ports\LogConfigPort;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\Queries\GetUserById\GetUserByIdHandler;
use App\Domain\User\Queries\GetUserById\GetUserByIdQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for GetUserByIdHandler — exercises every queryLoggingLevel
 * branch, the slow-query short-circuit, sampling extremes, and DTO mapping.
 */
#[AllowMockObjectsWithoutExpectations]
final class GetUserByIdHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private LoggerInterface $logger;
    private LogConfigPort $loggingConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loggingConfig = $this->createMock(LogConfigPort::class);
    }

    public function test_returns_user_dto_when_found(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser(['id' => 5]));

        $dto = $this->makeHandler()->handle(new GetUserByIdQuery(id: 5));

        $this->assertInstanceOf(UserDTO::class, $dto);
        $this->assertSame(5, $dto->id);
    }

    public function test_returns_null_when_user_not_found(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findById')->willReturn(null);

        $this->assertNull($this->makeHandler()->handle(new GetUserByIdQuery(id: 9999)));
    }

    public function test_errors_level_logs_only_not_found(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findById')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(
                static fn (array $ctx): bool => $ctx['result'] === 'not_found'
            ));

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_all_level_logs_every_call(): void
    {
        $this->stubConfig('all');
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_slow_level_never_logs_fast_queries(): void
    {
        $this->stubConfig('slow');
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_slow_query_short_circuits_irrespective_of_level(): void
    {
        $this->stubConfig('errors', slowMs: 0);
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(
                static fn (array $ctx): bool => ($ctx['slow_query'] ?? false) === true
            ));

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_sampling_with_rate_one_always_logs(): void
    {
        $this->stubConfig('sampling', samplingRate: 1.0);
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_sampling_with_rate_zero_never_logs(): void
    {
        $this->stubConfig('sampling', samplingRate: 0.0);
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    public function test_unknown_level_is_silent(): void
    {
        $this->stubConfig('mystery');
        $this->repository->method('findById')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByIdQuery(id: 1));
    }

    private function makeHandler(): GetUserByIdHandler
    {
        return new GetUserByIdHandler($this->repository, $this->logger, $this->loggingConfig);
    }

    private function stubConfig(
        string $level,
        int $slowMs = 1_000_000,
        float $samplingRate = 0.0,
    ): void {
        $this->loggingConfig->method('queryLoggingLevel')->willReturn($level);
        $this->loggingConfig->method('slowQueryThresholdMs')->willReturn($slowMs);
        $this->loggingConfig->method('samplingRate')->willReturn($samplingRate);
    }
}
