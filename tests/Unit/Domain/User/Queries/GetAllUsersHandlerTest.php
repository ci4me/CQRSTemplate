<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Queries;

use App\Domain\User\Queries\GetAllUsers\GetAllUsersHandler;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersQuery;
use App\Domain\User\Repositories\UserRepository;
use App\Infrastructure\Logging\CodeIgniterLogConfig;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for GetAllUsersHandler.
 *
 * Tests query execution with pagination, filtering, and logging.
 *
 * @package Tests\Unit\Domain\User\Queries
 */
final class GetAllUsersHandlerTest extends UnitTestCase
{
    private UserRepository $repository;
    private GetAllUsersHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(UserRepository::class);
        $logger = LoggerFactory::create('test.user.queries');
        $loggingConfig = new CodeIgniterLogConfig(new Logging());
        $this->handler = new GetAllUsersHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_paginated_results_page_1(): void
    {
        $query = new GetAllUsersQuery(page: 1, perPage: 20);
        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'email' => 'user1@example.com']),
            UserFactory::createPersistedUser(['id' => 2, 'email' => 'user2@example.com']),
            UserFactory::createPersistedUser(['id' => 3, 'email' => 'user3@example.com']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 100,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 5,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, '')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('perPage', $result);
        $this->assertArrayHasKey('lastPage', $result);
        $this->assertEquals(100, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(20, $result['perPage']);
        $this->assertEquals(5, $result['lastPage']);
        $this->assertCount(3, $result['data']);
    }

    public function test_returns_paginated_results_page_2(): void
    {
        $query = new GetAllUsersQuery(page: 2, perPage: 10);
        $users = [
            UserFactory::createPersistedUser(['id' => 11, 'email' => 'user11@example.com']),
            UserFactory::createPersistedUser(['id' => 12, 'email' => 'user12@example.com']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 25,
            'page' => 2,
            'perPage' => 10,
            'totalPages' => 3,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(2, 10, false, '')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(25, $result['total']);
        $this->assertEquals(2, $result['page']);
        $this->assertEquals(10, $result['perPage']);
        $this->assertEquals(3, $result['lastPage']);
        $this->assertCount(2, $result['data']);
    }

    public function test_handles_search_term(): void
    {
        $query = new GetAllUsersQuery(
            page: 1,
            perPage: 20,
            includeInactive: false,
            searchTerm: 'john'
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'email' => 'john@example.com', 'name' => 'John Doe']),
            UserFactory::createPersistedUser(['id' => 2, 'email' => 'johnny@example.com', 'name' => 'Johnny Smith']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 2,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, 'john')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    public function test_handles_include_inactive_flag(): void
    {
        $query = new GetAllUsersQuery(
            page: 1,
            perPage: 20,
            includeInactive: true
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'deletedAt' => null]),
            UserFactory::createPersistedUser(['id' => 2, 'deletedAt' => '2025-10-20 10:00:00']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 2,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, true, '')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    public function test_returns_empty_results(): void
    {
        $query = new GetAllUsersQuery(page: 1, perPage: 20);

        $expectedResult = [
            'data' => [],
            'total' => 0,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 0,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, '')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertIsArray($result);
        $this->assertEmpty($result['data']);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['lastPage']);
    }

    public function test_pagination_metadata_structure(): void
    {
        $query = new GetAllUsersQuery(page: 3, perPage: 15);
        $users = [
            UserFactory::createPersistedUser(['id' => 31]),
            UserFactory::createPersistedUser(['id' => 32]),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 47,
            'page' => 3,
            'perPage' => 15,
            'totalPages' => 4,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        // Verify all required keys exist
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('perPage', $result);
        $this->assertArrayHasKey('lastPage', $result);

        // Verify data types
        $this->assertIsArray($result['data']);
        $this->assertIsInt($result['total']);
        $this->assertIsInt($result['page']);
        $this->assertIsInt($result['perPage']);
        $this->assertIsInt($result['lastPage']);
    }

    public function test_calculates_total_pages_correctly(): void
    {
        $query = new GetAllUsersQuery(page: 1, perPage: 10);
        $users = array_fill(0, 10, UserFactory::createPersistedUser());

        // 47 total / 10 per page = 5 pages
        $expectedResult = [
            'data' => $users,
            'total' => 47,
            'page' => 1,
            'perPage' => 10,
            'totalPages' => 5,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(5, $result['lastPage']);
    }

    public function test_throws_exception_on_repository_failure(): void
    {
        $query = new GetAllUsersQuery(page: 1, perPage: 20);

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->willThrowException(new \RuntimeException('Database connection failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');

        $this->handler->handle($query);
    }

    public function test_handles_combined_search_and_include_inactive(): void
    {
        $query = new GetAllUsersQuery(
            page: 1,
            perPage: 20,
            includeInactive: true,
            searchTerm: 'test'
        );

        $expectedResult = [
            'data' => [
                UserFactory::createPersistedUser(['id' => 1, 'email' => 'test@example.com']),
            ],
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, true, 'test')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
    }
}
