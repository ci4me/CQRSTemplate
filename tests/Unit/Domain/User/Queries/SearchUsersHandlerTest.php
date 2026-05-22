<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Queries;

use App\Domain\Shared\Ports\LogConfigPort;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\Queries\SearchUsers\SearchUsersHandler;
use App\Domain\User\Queries\SearchUsers\SearchUsersQuery;
use App\Infrastructure\Logging\CodeIgniterLogConfig;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for SearchUsersHandler.
 *
 * Tests advanced search functionality with multiple filter combinations.
 *
 * @package Tests\Unit\Domain\User\Queries
 */
#[AllowMockObjectsWithoutExpectations]
final class SearchUsersHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private SearchUsersHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $logger = LoggerFactory::create('test.user.queries');
        $loggingConfig = new CodeIgniterLogConfig(new Logging());
        $this->handler = new SearchUsersHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_searches_by_email_filter(): void
    {
        $query = new SearchUsersQuery(
            email: 'john@example.com',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'email' => 'john@example.com']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, 'john@example.com', null, null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('john@example.com', $result['data'][0]->email);
    }

    public function test_searches_by_role_filter(): void
    {
        $query = new SearchUsersQuery(
            role: 'admin',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'role' => 'admin']),
            UserFactory::createPersistedUser(['id' => 2, 'role' => 'admin']),
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
            ->with(1, 20, false, '', 'admin', null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    public function test_searches_by_status_filter(): void
    {
        $query = new SearchUsersQuery(
            status: 'inactive',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'status' => 'inactive']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, '', null, 'inactive')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
    }

    public function test_searches_with_multiple_filters_combined(): void
    {
        $query = new SearchUsersQuery(
            email: 'admin',
            role: 'admin',
            status: 'active',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser([
                'id' => 1,
                'email' => 'admin@example.com',
                'role' => 'admin',
                'status' => 'active',
            ]),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, 'admin', 'admin', 'active')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
    }

    public function test_searches_with_pagination(): void
    {
        $query = new SearchUsersQuery(
            role: 'customer',
            page: 2,
            perPage: 10
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 11, 'role' => 'customer']),
            UserFactory::createPersistedUser(['id' => 12, 'role' => 'customer']),
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
            ->with(2, 10, false, '', 'customer', null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(25, $result['total']);
        $this->assertEquals(2, $result['page']);
        $this->assertEquals(10, $result['perPage']);
        $this->assertEquals(3, $result['lastPage']);
        $this->assertCount(2, $result['data']);
    }

    public function test_returns_empty_search_results(): void
    {
        $query = new SearchUsersQuery(
            email: 'nonexistent@example.com',
            page: 1,
            perPage: 20
        );

        $expectedResult = [
            'data' => [],
            'total' => 0,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 0,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, 'nonexistent@example.com', null, null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertIsArray($result);
        $this->assertEmpty($result['data']);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['lastPage']);
    }

    public function test_search_metadata_structure(): void
    {
        $query = new SearchUsersQuery(
            role: 'customer',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'role' => 'customer']),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
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

    public function test_searches_without_filters(): void
    {
        $query = new SearchUsersQuery(
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1]),
            UserFactory::createPersistedUser(['id' => 2]),
            UserFactory::createPersistedUser(['id' => 3]),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 3,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, '', null, null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    public function test_throws_exception_on_repository_failure(): void
    {
        $query = new SearchUsersQuery(
            email: 'test@example.com',
            page: 1,
            perPage: 20
        );

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->willThrowException(new \RuntimeException('Database query failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database query failed');

        $this->handler->handle($query);
    }

    public function test_searches_with_email_and_role_filters(): void
    {
        $query = new SearchUsersQuery(
            email: 'admin',
            role: 'admin',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser([
                'id' => 1,
                'email' => 'admin@example.com',
                'role' => 'admin',
            ]),
        ];

        $expectedResult = [
            'data' => $users,
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, 'admin', 'admin', null)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
    }

    public function test_searches_with_role_and_status_filters(): void
    {
        $query = new SearchUsersQuery(
            role: 'customer',
            status: 'active',
            page: 1,
            perPage: 20
        );

        $users = [
            UserFactory::createPersistedUser(['id' => 1, 'role' => 'customer', 'status' => 'active']),
            UserFactory::createPersistedUser(['id' => 2, 'role' => 'customer', 'status' => 'active']),
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
            ->with(1, 20, false, '', 'customer', 'active')
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    public function test_always_excludes_inactive_users(): void
    {
        $query = new SearchUsersQuery(
            role: 'customer',
            page: 1,
            perPage: 20
        );

        // SearchUsersHandler always passes false for includeInactive
        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(
                $this->anything(),
                $this->anything(),
                false, // includeInactive is always false
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->handle($query);
    }

    public function test_converts_null_email_to_empty_string(): void
    {
        $query = new SearchUsersQuery(
            email: null,
            page: 1,
            perPage: 20
        );

        // When email is null, handler converts it to empty string
        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, false, '', null, null)
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => 20,
                'totalPages' => 0,
            ]);

        $this->handler->handle($query);
    }

    public function test_slow_search_branch_emits_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $cfg = $this->createMock(LogConfigPort::class);
        $cfg->method('slowQueryThresholdMs')->willReturn(0);

        $this->repository->method('findPaginated')->willReturn([
            'data' => [UserFactory::createPersistedUser()],
            'total' => 1,
            'page' => 1,
            'perPage' => 20,
            'totalPages' => 1,
        ]);

        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with('Slow search query detected', $this->anything());

        $handler = new SearchUsersHandler($this->repository, $logger, $cfg);
        $handler->handle(new SearchUsersQuery(email: 'slow@example.com', page: 1, perPage: 20));
    }
}
