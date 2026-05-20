<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedHandler;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedQuery;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

final class GetCookiesPaginatedHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private GetCookiesPaginatedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.queries');
        $loggingConfig = new Logging();
        $this->handler = new GetCookiesPaginatedHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_paginated_results(): void
    {
        $query = new GetCookiesPaginatedQuery(page: 1, perPage: 20);
        $cookies = CookieFactory::createMultiple(20);

        $expectedResult = [
            'data' => $cookies,
            'total' => 100,
            'page' => 1,
            'perPage' => 20,
            'lastPage' => 5,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, null, false)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(100, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(20, $result['perPage']);
    }

    public function test_handles_search_term(): void
    {
        $query = new GetCookiesPaginatedQuery(
            page: 1,
            perPage: 20,
            searchTerm: 'Chocolate'
        );

        $cookies = CookieFactory::createMultiple(5);
        $expectedResult = [
            'data' => $cookies,
            'total' => 5,
            'page' => 1,
            'perPage' => 20,
            'lastPage' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, 'Chocolate', false)
            ->willReturn($expectedResult);

        $result = $this->handler->handle($query);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(5, $result['data']);
    }

    public function test_handles_include_inactive(): void
    {
        $query = new GetCookiesPaginatedQuery(
            page: 1,
            perPage: 20,
            searchTerm: null,
            includeInactive: true
        );

        $expectedResult = [
            'data' => [],
            'total' => 0,
            'page' => 1,
            'perPage' => 20,
            'lastPage' => 1,
        ];

        $this->repository->expects($this->once())
            ->method('findPaginated')
            ->with(1, 20, null, true)
            ->willReturn($expectedResult);

        $this->handler->handle($query);
    }
}
