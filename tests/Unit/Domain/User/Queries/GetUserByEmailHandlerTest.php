<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Queries;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Ports\LogConfigPort;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\Queries\GetUserByEmail\GetUserByEmailHandler;
use App\Domain\User\Queries\GetUserByEmail\GetUserByEmailQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for GetUserByEmailHandler — happy path, miss, every
 * queryLoggingLevel branch, and slow-query path.
 */
#[AllowMockObjectsWithoutExpectations]
final class GetUserByEmailHandlerTest extends UnitTestCase
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
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser(['id' => 9]));

        $dto = $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'john.doe@example.com'));

        $this->assertInstanceOf(UserDTO::class, $dto);
        $this->assertSame(9, $dto->id);
    }

    public function test_returns_null_when_email_not_found(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findByEmail')->willReturn(null);

        $this->assertNull($this->makeHandler()->handle(new GetUserByEmailQuery(email: 'unknown@example.com')));
    }

    public function test_invalid_email_format_throws_validation(): void
    {
        $this->stubConfig('errors');
        $this->repository->expects($this->never())->method('findByEmail');

        $this->expectException(ValidationException::class);

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'not-an-email'));
    }

    public function test_errors_level_logs_only_misses(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findByEmail')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(
                static fn (array $ctx): bool => $ctx['result'] === 'not_found'
            ));

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'miss@example.com'));
    }

    public function test_errors_level_skips_logging_for_hit(): void
    {
        $this->stubConfig('errors');
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'hit@example.com'));
    }

    public function test_all_level_logs_every_call(): void
    {
        $this->stubConfig('all');
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'all@example.com'));
    }

    public function test_slow_level_skips_fast_queries(): void
    {
        $this->stubConfig('slow');
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'fast@example.com'));
    }

    public function test_slow_query_short_circuit_logs_as_slow(): void
    {
        $this->stubConfig('errors', slowMs: 0);
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Query executed', $this->callback(
                static fn (array $ctx): bool => ($ctx['slow_query'] ?? false) === true
            ));

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'slow@example.com'));
    }

    public function test_sampling_with_rate_one_always_logs(): void
    {
        $this->stubConfig('sampling', samplingRate: 1.0);
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->once())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'sample@example.com'));
    }

    public function test_sampling_with_rate_zero_never_logs(): void
    {
        $this->stubConfig('sampling', samplingRate: 0.0);
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'sample0@example.com'));
    }

    public function test_unknown_level_is_silent(): void
    {
        $this->stubConfig('mystery');
        $this->repository->method('findByEmail')->willReturn(UserFactory::createPersistedUser());

        $this->logger->expects($this->never())->method('info');

        $this->makeHandler()->handle(new GetUserByEmailQuery(email: 'mystery@example.com'));
    }

    private function makeHandler(): GetUserByEmailHandler
    {
        return new GetUserByEmailHandler($this->repository, $this->logger, $this->loggingConfig);
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
